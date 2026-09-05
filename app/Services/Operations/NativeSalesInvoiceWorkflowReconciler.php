<?php

namespace App\Services\Operations;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use ReflectionEnum;
use ReflectionMethod;
use UnitEnum;

class NativeSalesInvoiceWorkflowReconciler
{
    public function reconcileDraftForSubmit(int $invoiceId): array
    {
        $invoice=$this->invoice($invoiceId);

        if (!$invoice) {
            return [
                'diagnostic'=>'native SalesInvoice model/row unavailable',
            ];
        }

        $table=$invoice->getTable();
        $columns=Schema::getColumnListing($table);
        $before=$invoice->getAttributes();

        /*
         * ERP-10.31.72 inspects the ACTUAL submit() method rather than a broad
         * file window around the error string. This prevents the diagnostic
         * from stopping immediately before the real guard.
         */
        $submitSource=$this->submitMethodSource();
        $contract=$this->resolveDraftContract(
            $invoice,
            $submitSource
        );

        $changed=[];

        foreach ($contract['fields'] as $field=>$value) {
            if (!in_array($field,$columns,true)) {
                continue;
            }

            try {
                DB::table($table)
                    ->where(
                        $invoice->getKeyName(),
                        $invoiceId
                    )
                    ->update([
                        $field=>$value,
                        ...(in_array('updated_at',$columns,true)
                            ? ['updated_at'=>now()]
                            : []),
                    ]);

                $changed[$field]=$value;
            } catch (\Throwable) {
            }
        }

        /*
         * If the exact guard did not expose an alias field, boolean Draft
         * flags remain safe compatibility fields when the native schema has
         * them. They do not affect amounts or accounting entries.
         */
        foreach (['is_draft','draft'] as $field) {
            if (!in_array($field,$columns,true)) {
                continue;
            }

            try {
                DB::table($table)
                    ->where(
                        $invoice->getKeyName(),
                        $invoiceId
                    )
                    ->update([$field=>1]);

                $changed[$field]=1;
            } catch (\Throwable) {
            }
        }

        $invoice->refresh();

        $after=$invoice->getAttributes();
        $draftMethod=$this->draftMethodDiagnostic(
            $invoice
        );

        return [
            'changed'=>$changed,
            'contract'=>$contract,
            'diagnostic'=>$this->diagnostic(
                $table,
                $before,
                $after,
                $submitSource,
                $draftMethod,
                $contract
            ),
        ];
    }

    public function diagnosticForInvoice(
        int $invoiceId
    ): string {
        $invoice=$this->invoice($invoiceId);

        if (!$invoice) {
            return 'native invoice unavailable';
        }

        $attributes=$invoice->getAttributes();
        $submitSource=$this->submitMethodSource();
        $contract=$this->resolveDraftContract(
            $invoice,
            $submitSource
        );
        $draftMethod=$this->draftMethodDiagnostic(
            $invoice
        );

        return $this->diagnostic(
            $invoice->getTable(),
            $attributes,
            $attributes,
            $submitSource,
            $draftMethod,
            $contract
        );
    }

    private function invoice(
        int $invoiceId
    ): ?Model {
        if (
            $invoiceId<=0
            || !class_exists(
                \App\Models\SalesInvoice::class
            )
        ) {
            return null;
        }

        try {
            $model=app(
                \App\Models\SalesInvoice::class
            );

            if (!$model instanceof Model) {
                return null;
            }

            return $model
                ->newQuery()
                ->whereKey($invoiceId)
                ->first();
        } catch (\Throwable) {
            return null;
        }
    }

    private function submitMethodSource(): string
    {
        foreach ([
            \App\Services\Sales\SalesInvoiceService::class,
            \App\Http\Controllers\Sales\SalesInvoiceController::class,
        ] as $class) {
            if (
                !class_exists($class)
                || !method_exists($class,'submit')
            ) {
                continue;
            }

            try {
                $method=new ReflectionMethod(
                    $class,
                    'submit'
                );

                $file=$method->getFileName();

                if (
                    !$file
                    || !is_readable($file)
                ) {
                    continue;
                }

                $lines=file($file);

                if (!is_array($lines)) {
                    continue;
                }

                $source=implode(
                    '',
                    array_slice(
                        $lines,
                        max(
                            0,
                            $method->getStartLine()-1
                        ),
                        max(
                            1,
                            $method->getEndLine()
                            - $method->getStartLine()
                            + 1
                        )
                    )
                );

                $source=preg_replace(
                    '/\s+/',
                    ' ',
                    $source
                ) ?: $source;

                return trim(
                    mb_substr(
                        $source,
                        0,
                        7000
                    )
                );
            } catch (\Throwable) {
            }
        }

        return '';
    }

    private function resolveDraftContract(
        Model $invoice,
        string $submitSource
    ): array {
        $fields=[];
        $evidence=[];

        /*
         * 1. Resolve the model's exact Draft constant first.
         * Common native patterns:
         * SalesInvoice::STATUS_DRAFT
         * SalesInvoice::DRAFT
         */
        $constant=$this->draftConstant(
            $invoice::class
        );

        if ($constant !== null) {
            $fields['status']=$constant['value'];
            $evidence[]='model constant '
                .$constant['name']
                .'='
                .$this->displayValue(
                    $constant['value']
                );
        }

        /*
         * 2. Resolve enum cast backing value/case.
         */
        $enum=$this->draftEnumCast(
            $invoice,
            'status'
        );

        if ($enum !== null) {
            $fields['status']=$enum['value'];
            $evidence[]='status enum '
                .$enum['class']
                .'::'
                .$enum['case']
                .'='
                .$this->displayValue(
                    $enum['value']
                );
        }

        /*
         * 3. Parse the real submit() method for direct literal checks such as:
         * $invoice->status !== 'DRAFT'
         * $invoice->status != "draft"
         */
        if ($submitSource!=='') {
            if (
                preg_match_all(
                    '/\$invoice->([A-Za-z_][A-Za-z0-9_]*)\s*(?:!==|!=|===|==)\s*[\'"]([^\'"]+)[\'"]/',
                    $submitSource,
                    $matches,
                    PREG_SET_ORDER
                )
            ) {
                foreach ($matches as $match) {
                    $field=$match[1];
                    $literal=$match[2];

                    if (
                        $this->looksLikeDraft(
                            $literal
                        )
                    ) {
                        $fields[$field]=$literal;
                        $evidence[]='submit literal '
                            .$field
                            .'='
                            .$literal;
                    }
                }
            }

            /*
             * Parse class constants used by the submit guard:
             * $invoice->status !== SalesInvoice::STATUS_DRAFT
             */
            if (
                preg_match_all(
                    '/\$invoice->([A-Za-z_][A-Za-z0-9_]*)\s*(?:!==|!=|===|==)\s*([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)::([A-Z0-9_]*DRAFT[A-Z0-9_]*)/',
                    $submitSource,
                    $matches,
                    PREG_SET_ORDER
                )
            ) {
                foreach ($matches as $match) {
                    $field=$match[1];
                    $classToken=$match[2];
                    $constantName=$match[3];

                    $value=$this->resolveClassConstant(
                        $classToken,
                        $constantName,
                        $invoice
                    );

                    if ($value !== null) {
                        $fields[$field]=$value;
                        $evidence[]='submit constant '
                            .$field
                            .'='
                            .$classToken
                            .'::'
                            .$constantName
                            .'('
                            .$this->displayValue($value)
                            .')';
                    }
                }
            }

            /*
             * Parse in_array($invoice->status, ['DRAFT', ...])
             */
            if (
                preg_match_all(
                    '/in_array\s*\(\s*\$invoice->([A-Za-z_][A-Za-z0-9_]*)\s*,\s*\[([^\]]+)\]/',
                    $submitSource,
                    $matches,
                    PREG_SET_ORDER
                )
            ) {
                foreach ($matches as $match) {
                    $field=$match[1];
                    $list=$match[2];

                    if (
                        preg_match_all(
                            '/[\'"]([^\'"]*draft[^\'"]*)[\'"]/i',
                            $list,
                            $values
                        )
                    ) {
                        foreach ($values[1] as $value) {
                            $fields[$field]=$value;
                            $evidence[]='submit in_array '
                                .$field
                                .'='
                                .$value;
                            break;
                        }
                    }
                }
            }
        }

        /*
         * 4. If no exact contract was found, retain the existing status value
         * rather than blindly rewriting it. This keeps the patch conservative.
         */
        if (!$fields) {
            $current=$invoice->getAttribute(
                'status'
            );

            if ($current !== null) {
                $fields['status']=$current;
                $evidence[]='fallback existing status='
                    .$this->displayValue($current);
            }
        }

        return [
            'fields'=>$fields,
            'evidence'=>$evidence,
        ];
    }

    private function draftConstant(
        string $class
    ): ?array {
        if (!class_exists($class)) {
            return null;
        }

        try {
            $reflection=new ReflectionClass(
                $class
            );

            $constants=$reflection
                ->getConstants();

            $preferred=[
                'STATUS_DRAFT',
                'INVOICE_STATUS_DRAFT',
                'WORKFLOW_STATUS_DRAFT',
                'DRAFT',
            ];

            foreach ($preferred as $name) {
                if (
                    array_key_exists(
                        $name,
                        $constants
                    )
                ) {
                    $value=$this->scalarEnumValue(
                        $constants[$name]
                    );

                    if ($value !== null) {
                        return [
                            'name'=>$name,
                            'value'=>$value,
                        ];
                    }
                }
            }

            $matches=[];

            foreach ($constants as $name=>$value) {
                if (
                    !str_contains(
                        strtoupper((string)$name),
                        'DRAFT'
                    )
                ) {
                    continue;
                }

                $value=$this->scalarEnumValue(
                    $value
                );

                if ($value !== null) {
                    $matches[]=[
                        'name'=>$name,
                        'value'=>$value,
                    ];
                }
            }

            return count($matches)===1
                ? $matches[0]
                : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function draftEnumCast(
        Model $invoice,
        string $field
    ): ?array {
        try {
            $casts=$invoice->getCasts();
            $cast=$casts[$field] ?? null;

            if (
                !is_string($cast)
                || !enum_exists($cast)
            ) {
                return null;
            }

            $enum=new ReflectionEnum($cast);

            foreach ($enum->getCases() as $case) {
                if (
                    strtoupper($case->getName())
                    !== 'DRAFT'
                ) {
                    continue;
                }

                $value=$case->getValue();

                if ($value instanceof BackedEnum) {
                    return [
                        'class'=>$cast,
                        'case'=>$case->getName(),
                        'value'=>$value->value,
                    ];
                }

                return [
                    'class'=>$cast,
                    'case'=>$case->getName(),
                    'value'=>$case->getName(),
                ];
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private function resolveClassConstant(
        string $classToken,
        string $constant,
        Model $invoice
    ): mixed {
        $candidates=[];

        $trimmed=ltrim(
            $classToken,
            '\\'
        );

        if (
            $trimmed==='SalesInvoice'
            || str_ends_with(
                $trimmed,
                '\\SalesInvoice'
            )
        ) {
            $candidates[]=$invoice::class;
        }

        if (
            class_exists($trimmed)
        ) {
            $candidates[]=$trimmed;
        }

        if (
            !str_contains(
                $trimmed,
                '\\'
            )
        ) {
            foreach ([
                'App\\Models\\'.$trimmed,
                'App\\Enums\\'.$trimmed,
                'App\\Services\\Sales\\'.$trimmed,
            ] as $candidate) {
                if (class_exists($candidate)) {
                    $candidates[]=$candidate;
                }
            }
        }

        foreach (
            array_values(
                array_unique($candidates)
            )
            as $class
        ) {
            try {
                $reflection=new ReflectionClass(
                    $class
                );

                if (
                    !$reflection->hasConstant(
                        $constant
                    )
                ) {
                    continue;
                }

                return $this->scalarEnumValue(
                    $reflection
                        ->getConstant($constant)
                );
            } catch (\Throwable) {
            }
        }

        return null;
    }

    private function scalarEnumValue(
        mixed $value
    ): mixed {
        if (
            is_string($value)
            || is_int($value)
            || is_float($value)
            || is_bool($value)
        ) {
            return $value;
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        return null;
    }

    private function looksLikeDraft(
        string $value
    ): bool {
        return str_contains(
            strtolower(trim($value)),
            'draft'
        );
    }

    private function draftMethodDiagnostic(
        Model $invoice
    ): string {
        foreach ([
            'isDraft',
            'is_draft',
            'draft',
        ] as $name) {
            if (!method_exists($invoice,$name)) {
                continue;
            }

            try {
                $method=new ReflectionMethod(
                    $invoice,
                    $name
                );

                if (
                    !$method->isPublic()
                    || $method->getNumberOfRequiredParameters()>0
                ) {
                    continue;
                }

                $result=$invoice->{$name}();
                $source='';

                $file=$method->getFileName();

                if (
                    $file
                    && is_readable($file)
                ) {
                    $lines=file($file);

                    if (is_array($lines)) {
                        $slice=array_slice(
                            $lines,
                            max(
                                0,
                                $method->getStartLine()-1
                            ),
                            max(
                                1,
                                $method->getEndLine()
                                - $method->getStartLine()
                                + 1
                            )
                        );

                        $source=preg_replace(
                            '/\s+/',
                            ' ',
                            implode('',$slice)
                        ) ?: '';
                    }
                }

                return $name.'()='
                    .($result ? 'true' : 'false')
                    .($source!==''
                        ? ' source '
                            .mb_substr(
                                trim($source),
                                0,
                                900
                            )
                        : '');
            } catch (\Throwable) {
            }
        }

        return '';
    }

    private function statusMap(
        array $attributes
    ): array {
        $map=[];

        foreach ($attributes as $field=>$value) {
            $lower=strtolower(
                (string)$field
            );

            if (
                str_contains($lower,'status')
                || str_contains($lower,'draft')
                || in_array(
                    $lower,
                    [
                        'submitted_at',
                        'approved_at',
                        'posted_at',
                    ],
                    true
                )
            ) {
                $map[$field]=$value;
            }
        }

        return $map;
    }

    private function diagnostic(
        string $table,
        array $before,
        array $after,
        string $submitSource,
        string $draftMethod,
        array $contract
    ): string {
        $format=function(
            array $attrs
        ): string {
            $parts=[];

            foreach (
                $this->statusMap($attrs)
                as $field=>$value
            ) {
                $parts[]=$field
                    .'='
                    .$this->displayValue(
                        $value
                    );
            }

            return implode(',',$parts);
        };

        $parts=[
            'native table '.$table,
            'before '.$format($before),
            'after '.$format($after),
        ];

        if (
            !empty($contract['evidence'])
        ) {
            $parts[]='draft contract '
                .implode(
                    ' | ',
                    $contract['evidence']
                );
        }

        if ($draftMethod!=='') {
            $parts[]=$draftMethod;
        }

        if ($submitSource!=='') {
            $parts[]='submit method '
                .mb_substr(
                    $submitSource,
                    0,
                    2400
                );
        }

        return mb_substr(
            implode('; ',$parts),
            0,
            5200
        );
    }

    private function displayValue(
        mixed $value
    ): string {
        if ($value===null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value instanceof BackedEnum) {
            return (string)$value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if (
            is_scalar($value)
            || (
                is_object($value)
                && method_exists(
                    $value,
                    '__toString'
                )
            )
        ) {
            return trim((string)$value);
        }

        return get_debug_type($value);
    }
}
