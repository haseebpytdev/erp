import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '..', '..');
const read = (p) => fs.readFileSync(path.join(root, p), 'utf8');
const service = read('app/Services/Reports/TravelReportService.php');
const arrival = read('resources/views/reports/travel/movements/arrival.blade.php');
const departure = read('resources/views/reports/travel/movements/departure.blade.php');
const controller = read('app/Http/Controllers/Reports/TravelReportsController.php');
const release = read('config/et_erp_release.php');
const styles = read('resources/views/reports/travel/partials/movement-table-styles.blade.php');

let passed = 0;
const ok = (condition, message) => { if (!condition) throw new Error(message); passed++; };
const ordered = (source, labels) => labels.every((label, i) => source.indexOf(`<th>${label}</th>`) < (i + 1 < labels.length ? source.indexOf(`<th>${labels[i + 1]}</th>`) : source.length));

ok(service.includes("$arrival=['arrival_date','arrival_time','flight','sector','booking_no','customer','total_pax','adult','child','infant','makkah_hotel','transport'"), 'arrival authority order missing');
ok(service.includes("$departure=['departure_date','departure_time','flight','sector','booking_no','customer','total_pax','adult','child','infant','makkah_hotel','madinah_hotel','transport'"), 'departure authority order missing');
ok(ordered(arrival, ['Arrival Date','Arrival Time','Flight','Sector','Booking No.','Customer / Group','Total Pax','Adult','Child','Infant','Makkah Hotel','Transport']), 'arrival screen/print order mismatch');
ok(ordered(departure, ['Departure Date','Departure Time','Flight','Sector','Booking No.','Customer / Group','Total Pax','Adult','Child','Infant','Makkah Hotel','Madinah Hotel','Transport']), 'departure screen/print order mismatch');
ok(arrival.includes('data-et-travel-print-document="1"') && departure.includes('data-et-travel-print-document="1"'), 'print document root missing');
ok(controller.includes("$columns=$service->definition($movement)['columns']") && controller.includes("array_column($columns,'label')"), 'CSV does not use shared definition authority');
ok(service.includes("$makkahCheckin=['check_in','check_out','booking_no','customer','coming_from','total_pax','adult','child','infant','makkah_hotel','nights']"), 'unrelated check-in authority changed');
ok(service.includes("$makkahCheckout=['check_out','booking_no','customer'"), 'unrelated checkout authority changed');
ok(styles.includes('padding:3px 10px') && styles.includes('line-height:1.2') && styles.includes('min-height:0'), 'C86.3.2 density contract changed');
ok(styles.includes('min-width:220px') && styles.includes('white-space:nowrap'), 'customer width contract changed');
ok(release.includes("'asset_version' => 'ERP-11.3.378-C86.3.3'") && release.includes("'corrective_build' => 'C86.3.3'") && release.includes("'corrective_name' => 'Arrival Departure Operational Column Order'"), 'release identity mismatch');
ok(!service.includes('DB::table(\'arrival'), 'no new arrival data source introduced');
ok(!service.includes('DB::table(\'departure'), 'no new departure data source introduced');
ok(arrival.includes('<th>Flight</th><th>Sector</th><th>Booking No.</th>'), 'arrival flight/sector placement missing');
ok(departure.includes('<th>Flight</th><th>Sector</th><th>Booking No.</th>'), 'departure flight/sector placement missing');

console.log(`C86.3.3 arrival/departure column-order regression: ${passed} assertions passed`);
