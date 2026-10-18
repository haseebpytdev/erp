import assert from 'node:assert/strict';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const root = process.cwd();
const servicePath = 'app/Services/Reports/TravelReportService.php';
const controllerPath = 'app/Http/Controllers/Reports/MobileMovementController.php';
const viewPath = 'resources/views/mobile/movements/index.blade.php';
const service = fs.readFileSync(`${root}/${servicePath}`, 'utf8');
const controller = fs.readFileSync(`${root}/${controllerPath}`, 'utf8');
const view = fs.readFileSync(`${root}/${viewPath}`, 'utf8');

const checks = [
  ['snapshot producer exists', service.includes('public function mobileMovementSnapshot(')],
  ['canonical movement map exists', service.includes('public const MOVEMENTS=')],
  ['all six movement keys are canonical', ['arrival', 'makkah-checkin', 'makkah-checkout', 'madinah-checkin', 'madinah-checkout', 'departure'].every((key) => service.includes(`'${key}'`))],
  ['snapshot iterates canonical movement keys', service.includes('foreach (array_keys(self::MOVEMENTS) as $key)')],
  ['event key is server-produced', service.includes("'key' => $entry['key']")],
  ['movement label is server-produced', service.includes("'movement' => self::MOVEMENTS[$entry['key']]")],
  ['movement label uses one canonical map', !service.includes('mobileMovementLabels') && !controller.includes('mobileMovementLabels')],
  ['view consumes movement identity', view.includes("$event['movement']")],
  ['view consumes event key', view.includes("$event['key']")],
  ['view consumes action', view.includes("$event['action']")],
  ['view consumes date and time', view.includes("$event['date']") && view.includes("$event['time']")],
  ['view consumes booking and customer fields', view.includes("$event['booking_no']") && view.includes("$event['customer']")],
  ['view consumes pax hotel and flight fields', view.includes("$event['pax']") && view.includes("$event['hotel']") && view.includes("$event['flight']")],
  ['display list remains capped', service.includes('array_slice($upcoming, 0, 20)')],
  ['tomorrow excludes today', service.includes("$dateEvents->where('date', $tomorrow)")],
  ['next three day window starts tomorrow', service.includes("$entry['date'] >= $tomorrow")],
  ['next three day window ends through date', service.includes("$entry['date'] <= $through")],
  ['snapshot performs one movement evaluation per canonical key', service.includes('$events = $this->movementEvents($key, $window)')],
  ['controller delegates to snapshot', controller.includes('mobileMovementSnapshot(')],
  ['controller preserves six movement labels for cards/routes', controller.includes('private const MOVEMENTS =')],
  ['pre-optimization card contract included movement', execFileSync('git', ['show', '72728ae4c74a7bb572484401e582da2cacc5f840:app/Http/Controllers/Reports/MobileMovementController.php'], { encoding: 'utf8' }).includes("'movement' => self::MOVEMENTS[$movement]")],
  ['no duplicate movement renderer map introduced', !service.includes('case \'arrival\'') && !service.includes('case \'departure\'')],
];

for (const [name, passed] of checks) {
  assert.equal(passed, true, name);
}

console.log(`C90.7.3.1 mobile movement event contract regression: PASS (${checks.length} assertions)`);
