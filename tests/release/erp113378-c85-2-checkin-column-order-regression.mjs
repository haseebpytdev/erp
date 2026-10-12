import fs from 'node:fs';
import assert from 'node:assert/strict';

const service = fs.readFileSync('app/Services/Reports/TravelReportService.php', 'utf8');
const controller = fs.readFileSync('app/Http/Controllers/Reports/TravelReportsController.php', 'utf8');
const ok = (value, message) => assert.ok(value, message);
const ordered = ['check_in','check_out','booking_no','customer','coming_from','total_pax','adult','child','infant'];
const makkah = "$makkahCheckin=['check_in','check_out','booking_no','customer','coming_from','total_pax','adult','child','infant','makkah_hotel','nights']";
const madinah = "$madinahCheckin=['check_in','check_out','booking_no','customer','coming_from','total_pax','adult','child','infant','madinah_hotel','nights']";

ok(service.includes(makkah), 'Makkah Check-in uses the standard date-first column order');
ok(service.includes(madinah), 'Madinah Check-in uses the standard date-first column order');
ok(service.includes("'makkah_hotel'=>'Makkah Hotel'"), 'Makkah column is city-specific');
ok(service.includes("'madinah_hotel'=>'Madinah Hotel'"), 'Madinah column is city-specific');
ok(service.includes("if($key==='makkah-checkin')$columns=$makkahCheckin"), 'Makkah definition drives rendered columns');
ok(service.includes("if($key==='madinah-checkin')$columns=$madinahCheckin"), 'Madinah definition drives rendered columns');
ok(controller.includes("$columns=$service->definition($movement)['columns']") && controller.includes('array_column($columns,\'label\')'), 'CSV export uses the report definition column order');
ok(controller.includes("$columns=$service->definition($movement)['columns']") && controller.includes('foreach($columns as $column)'), 'CSV row values use the same definition order');
ok(ordered.every((key) => service.includes(key)), 'standard shared column keys remain present');
ok(service.includes('coming_from') && service.includes('makkahComingFrom'), 'Coming From authority remains unchanged');

console.log('ERP-11.3.378 C85.2 check-in column order regression: PASS / 10');
