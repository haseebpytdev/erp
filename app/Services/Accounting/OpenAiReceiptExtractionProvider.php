<?php

namespace App\Services\Accounting;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class OpenAiReceiptExtractionProvider implements ReceiptExtractionProvider
{
    public function extract(UploadedFile $file): array
    {
        $started = microtime(true);
        $correlationId = (string) Str::uuid();
        $key = (string) config('services.receipt_ai.key', '');
        $model = (string) config('services.receipt_ai.model', '');
        if ((string) config('services.receipt_ai.provider', 'openai') !== 'openai' || $key === '' || $model === '') {
            return ['configured' => false, 'error' => 'AI receipt capture is not configured.'];
        }

        try {
            $mime = (string) $file->getMimeType();
            $data = base64_encode((string) file_get_contents($file->getRealPath()));
            $content = [[
                'type' => str_starts_with($mime, 'image/') ? 'input_image' : 'input_file',
                str_starts_with($mime, 'image/') ? 'image_url' : 'file_data' => 'data:'.$mime.';base64,'.$data,
            ]];
            $content[] = ['type' => 'input_text', 'text' => $this->instructions()];

            $response = Http::timeout((int) config('services.receipt_ai.timeout', 25))
                ->connectTimeout((int) config('services.receipt_ai.connect_timeout', 8))
                ->withToken($key)
                ->acceptJson()
                ->post('https://api.openai.com/v1/responses', [
                    'model' => $model,
                    'input' => [['role' => 'user', 'content' => $content]],
                    'text' => ['format' => [
                        'type' => 'json_schema',
                        'name' => 'receipt_extraction',
                        'strict' => true,
                        'schema' => $this->schema(),
                    ]],
                ]);

            if (! $response->successful()) {
                $this->logFailure($correlationId, $response->status(), $started, $response->json('error.type'), $response->json('error.code'), $response->header('x-request-id'));
                return ['configured' => true, 'error' => 'AI receipt capture is temporarily unavailable.'];
            }
            $json = $this->responseText($response->json());
            $decoded = is_string($json) ? json_decode($json, true) : $json;
            return is_array($decoded) ? ['configured' => true, 'data' => $decoded] : ['configured' => true, 'error' => 'Receipt data could not be read.'];
        } catch (ConnectionException) {
            return ['configured' => true, 'error' => 'AI receipt capture timed out.'];
        } catch (\Throwable) {
            return ['configured' => true, 'error' => 'Receipt data could not be read.'];
        }
    }

    /** @param array<string,mixed> $payload */
    private function responseText(array $payload): ?string
    {
        foreach ((array) ($payload['output'] ?? []) as $item) {
            foreach ((array) ($item['content'] ?? []) as $content) {
                if (($content['type'] ?? null) === 'output_text' && is_string($content['text'] ?? null) && trim($content['text']) !== '') {
                    return $content['text'];
                }
            }
        }
        $fallback = $payload['output_text'] ?? null;
        return is_string($fallback) && trim($fallback) !== '' ? $fallback : null;
    }

    private function logFailure(string $correlationId, int $status, float $started, mixed $errorType, mixed $errorCode, ?string $requestId): void
    {
        $category = $status === 429 ? 'rate_limited' : ($status >= 500 ? 'provider_server_error' : ($status >= 400 ? 'provider_request_error' : 'provider_error'));
        Log::warning('receipt_ai provider failure', array_filter([
            'correlation_id' => $correlationId,
            'provider' => 'openai',
            'http_status' => $status,
            'failure_category' => $category,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'provider_error_type' => is_string($errorType) ? $errorType : null,
            'provider_error_code' => is_string($errorCode) ? $errorCode : null,
            'provider_request_id' => $requestId,
        ], static fn ($value): bool => $value !== null && $value !== ''));
    }

    private function instructions(): string
    {
        return 'Extract only the receipt fields in the supplied schema. Do not invent values. Use null and confidence 0 when unreadable. If totals or dates conflict, return a warning and lower confidence. Account data may contain only visibly printed last digits; never reconstruct a full account number.';
    }

    /** @return array<string,mixed> */
    private function schema(): array
    {
        $field = ['type' => ['string', 'null']];
        $confidence = ['type' => 'number', 'minimum' => 0, 'maximum' => 1];
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['receipt_date','receipt_time','amount','currency','transaction_reference','counterparty_name','bank_name','payment_method','visible_account_last_digits','voucher_type_suggestion','narration','warnings','confidence'], 'properties' => [
            'receipt_date' => ['type' => ['string','null']], 'receipt_time' => $field,
            'amount' => ['type' => ['number','null']], 'currency' => $field,
            'transaction_reference' => $field, 'counterparty_name' => $field, 'bank_name' => $field,
            'payment_method' => $field, 'visible_account_last_digits' => $field,
            'voucher_type_suggestion' => ['type' => 'string', 'enum' => ['PAYMENT','RECEIPT','UNKNOWN']],
            'narration' => $field, 'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
            'confidence' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                'receipt_date' => $confidence, 'receipt_time' => $confidence, 'amount' => $confidence,
                'currency' => $confidence, 'transaction_reference' => $confidence, 'counterparty_name' => $confidence,
                'bank_name' => $confidence, 'payment_method' => $confidence, 'visible_account_last_digits' => $confidence,
                'voucher_type_suggestion' => $confidence, 'narration' => $confidence,
            ], 'required' => ['receipt_date','receipt_time','amount','currency','transaction_reference','counterparty_name','bank_name','payment_method','visible_account_last_digits','voucher_type_suggestion','narration']],
        ]];
    }
}
