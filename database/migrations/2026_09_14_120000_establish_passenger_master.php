<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('passengers')) {
            Schema::create('passengers', function (Blueprint $table): void {
                $table->id();
                $table->string('title', 20)->nullable();
                $table->string('sex', 20)->nullable();
                $table->string('first_name', 100)->nullable();
                $table->string('last_name', 100)->nullable();
                $table->date('date_of_birth')->nullable();
                $table->string('passport_no', 50)->nullable();
                $table->date('passport_expiry')->nullable();
                $table->string('nationality', 100)->nullable();
                $table->string('issuing_country', 100)->nullable();
                $table->string('status', 30)->default('active');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->index('passport_no');
            });
        } else {
            $columns = Schema::getColumnListing('passengers');
            Schema::table('passengers', function (Blueprint $table) use ($columns): void {
                if (! in_array('title', $columns, true) && ! in_array('salutation', $columns, true)) $table->string('title', 20)->nullable();
                if (! in_array('sex', $columns, true) && ! in_array('gender', $columns, true)) $table->string('sex', 20)->nullable();
                if (! in_array('passport_no', $columns, true) && ! in_array('passport_number', $columns, true)) $table->string('passport_no', 50)->nullable()->index();
                if (! in_array('first_name', $columns, true) && ! in_array('given_name', $columns, true) && ! in_array('name', $columns, true) && ! in_array('passenger_name', $columns, true)) $table->string('first_name', 100)->nullable();
                if (! in_array('last_name', $columns, true) && ! in_array('surname', $columns, true) && ! in_array('family_name', $columns, true)) $table->string('last_name', 100)->nullable();
                if (! in_array('date_of_birth', $columns, true) && ! in_array('dob', $columns, true) && ! in_array('birth_date', $columns, true)) $table->date('date_of_birth')->nullable();
                if (! in_array('passport_expiry', $columns, true) && ! in_array('passport_expiry_date', $columns, true)) $table->date('passport_expiry')->nullable();
                if (! in_array('nationality', $columns, true) && ! in_array('nationality_name', $columns, true) && ! in_array('country', $columns, true)) $table->string('nationality', 100)->nullable();
                if (! in_array('issuing_country', $columns, true) && ! in_array('passport_issuing_country', $columns, true) && ! in_array('document_issuing_country', $columns, true)) $table->string('issuing_country', 100)->nullable();
                if (! in_array('status', $columns, true) && ! in_array('is_active', $columns, true) && ! in_array('active', $columns, true)) $table->string('status', 30)->default('active');
                if (! in_array('created_by', $columns, true) && ! in_array('created_by_id', $columns, true) && ! in_array('user_id', $columns, true)) $table->unsignedBigInteger('created_by')->nullable();
                if (! in_array('created_at', $columns, true)) $table->timestamp('created_at')->nullable();
                if (! in_array('updated_at', $columns, true)) $table->timestamp('updated_at')->nullable();
            });
        }

        if (! Schema::hasTable('booking_passengers') || ! Schema::hasTable('passengers')) return;
        $target = Schema::getColumnListing('passengers');
        $source = Schema::getColumnListing('booking_passengers');
        $value = static function (object $row, array $columns, array $names): mixed {
            foreach ($names as $name) if (in_array($name, $columns, true) && isset($row->{$name}) && $row->{$name} !== '') return $row->{$name};
            return null;
        };
        foreach (DB::table('booking_passengers')->orderBy('id')->get() as $booking) {
            $passport = strtoupper(preg_replace('/\s+/', '', trim((string) $value($booking, $source, ['passport_no', 'passport_number']))) ?? '');
            $first = trim((string) $value($booking, $source, ['first_name', 'given_name', 'name', 'passenger_name']));
            $last = trim((string) $value($booking, $source, ['last_name', 'surname', 'family_name']));
            $dob = $value($booking, $source, ['date_of_birth', 'dob', 'birth_date']);
            if ($passport !== '' && DB::table('passengers')->where(function ($query) use ($target, $passport): void { foreach (['passport_no', 'passport_number'] as $column) if (in_array($column, $target, true)) $query->orWhereRaw('LOWER(`'.$column.'`) = ?', [strtolower($passport)]); })->exists()) continue;
            $duplicate = false;
            if ($passport !== '') $duplicate = DB::table('passengers')->where(function ($query) use ($target, $passport): void { foreach (['passport_no', 'passport_number'] as $column) if (in_array($column, $target, true)) $query->orWhereRaw('LOWER(`'.$column.'`) = ?', [strtolower($passport)]); })->exists();
            if (! $duplicate && $first !== '' && $dob && DB::table('passengers')->where(function ($query) use ($target, $first): void { foreach (['first_name', 'given_name', 'name', 'passenger_name'] as $column) if (in_array($column, $target, true)) $query->orWhereRaw('LOWER(`'.$column.'`) = ?', [strtolower($first)]); })->where(function ($query) use ($target, $dob): void { foreach (['date_of_birth', 'dob', 'birth_date'] as $column) if (in_array($column, $target, true)) $query->orWhereDate($column, $dob); })->exists()) $duplicate = true;
            if ($duplicate) continue;
            $row = [];
            $put = static function (array &$out, array $columns, array $names, mixed $value): void { if ($value === null || $value === '') return; foreach ($names as $name) if (in_array($name, $columns, true)) { $out[$name] = $value; return; } };
            $put($row, $target, ['title', 'salutation'], $value($booking, $source, ['title', 'salutation']));
            $put($row, $target, ['sex', 'gender'], $value($booking, $source, ['sex', 'gender']));
            $put($row, $target, ['first_name', 'given_name', 'name', 'passenger_name'], $first);
            $put($row, $target, ['last_name', 'surname', 'family_name'], $last);
            $put($row, $target, ['date_of_birth', 'dob', 'birth_date'], $dob);
            $put($row, $target, ['passport_no', 'passport_number'], $passport);
            $put($row, $target, ['passport_expiry', 'passport_expiry_date'], $value($booking, $source, ['passport_expiry', 'passport_expiry_date']));
            $put($row, $target, ['nationality', 'nationality_name', 'country'], $value($booking, $source, ['nationality', 'nationality_name', 'country']));
            $put($row, $target, ['issuing_country', 'passport_issuing_country', 'document_issuing_country'], $value($booking, $source, ['issuing_country', 'passport_issuing_country', 'document_issuing_country']));
            $put($row, $target, ['status'], 'active');
            if (in_array('created_at', $target, true)) $row['created_at'] = now();
            if (in_array('updated_at', $target, true)) $row['updated_at'] = now();
            if ($row) DB::table('passengers')->insert($row);
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive: Passenger Master data and booking history are retained.
    }
};
