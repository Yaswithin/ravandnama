import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

process.env.TZ = "Pacific/Honolulu";
const root = resolve(dirname(fileURLToPath(import.meta.url)), "..");
async function asModule(path) {
    const source = readFileSync(path, "utf8");
    return JSON.stringify(`data:text/javascript;base64,${Buffer.from(source).toString("base64")}`);
}

const adapterPath = resolve(root, "public/assets/js/utils/date-time.js");
const source = readFileSync(adapterPath, "utf8")
    .replace('"../vendor/jalaali-js.js"', await asModule(resolve(root, "public/assets/js/vendor/jalaali-js.js")))
    .replace('"../vendor/luxon.js"', await asModule(resolve(root, "public/assets/js/vendor/luxon.js")));
const dates = await import(`data:text/javascript;base64,${Buffer.from(source).toString("base64")}`);

let assertions = 0;
function check(actual, expected, label) {
    assert.deepEqual(actual, expected, label);
    assertions += 1;
}
function rejects(action, label) {
    assert.throws(action, RangeError, label);
    assertions += 1;
}

check(dates.jalaliMonthLength(1395, 12), 30, "leap-year Esfand has thirty days");
check(dates.jalaliMonthLength(1394, 12), 29, "common-year Esfand has twenty-nine days");
check(dates.isJalaliLeapYear(1395), true, "library leap-year result is used");
check(dates.shiftJalaliMonth("1404-12-29", 1), "1405-01-29", "month navigation crosses Nowruz");
check(dates.shiftJalaliMonth("1395-12-30", 1), "1396-01-30", "month navigation clamps leap Esfand date to the next month length");
check(dates.shiftJalaliDate("1405-01-01", -1), "1404-12-29", "keyboard day movement crosses the year boundary");
check(dates.jalaliWeekday("1405-01-01"), 0, "Jalali week starts Saturday");
check(dates.formatJalaliMonth(1405, 7), "مهر ۱۴۰۵", "calendar month heading uses Persian formatting");
check(dates.formatGregorianLong("2026-09-30"), "30 September 2026", "Gregorian reference is readable and timezone independent");

check(dates.canonicalizeRfc3339("2026-09-30T18:30:00.125+03:30"), "2026-09-30T15:00:00.125Z", "UTC normalization retains supported milliseconds");
check(dates.utcToUserLocal("2026-09-30T15:00:00.125Z", "Asia/Tehran").gregorianDate, "2026-09-30", "canonical instant displays using the saved zone");
const dateAtMidnightBoundary = dates.utcToUserLocal("2026-09-30T02:00:00Z", "America/New_York");
check(dateAtMidnightBoundary.gregorianDate, "2026-09-29", "UTC instant may display on the prior local calendar day");
check(dates.localDateTimeToUtc(dateAtMidnightBoundary.jalaliDate, dateAtMidnightBoundary.localTime, "America/New_York"), "2026-09-30T02:00:00Z", "midnight-crossing local display round-trips to the same instant");
check(dates.currentDateInTimezone("America/New_York", new Date("2026-09-30T02:00:00Z")).gregorianDate, "2026-09-29", "Today follows the saved zone rather than the process timezone");

const legacy = dates.legacyWallClockToDisplay("2026-09-30 18:30:42.123456");
check(legacy.kind, "legacy-wall-clock", "legacy representation remains explicitly identified");
check(legacy.gregorianDate, "2026-09-30", "legacy Gregorian components are preserved");
check(legacy.localTime, "18:30:42", "legacy clock components are not shifted");
check(legacy.jalaliDate, "1405-07-08", "legacy date converts to Jalali without timezone conversion");
check(legacy.primary, "۸ مهر ۱۴۰۵، ۱۸:۳۰", "legacy primary display is Jalali-first");
check(legacy.secondary, "30 September 2026 · 18:30", "legacy Gregorian reference keeps its wall-clock time");

check(dates.localDateTimeToUtc("1405-07-08", "18:30:00", "Asia/Tehran"), "2026-09-30T15:00:00Z", "new form converts Jalali date and time in the saved Tehran zone");
check(dates.localDateTimeToUtc("1404-10-10", "21:00:00", "America/New_York"), "2026-01-01T02:00:00Z", "new form supports timezone conversion across UTC midnight");
const spring = dates.gregorianToJalali("2024-03-10");
rejects(() => dates.localDateTimeToUtc(spring, "02:30:00", "America/New_York"), "DST spring gap is rejected");
const fall = dates.gregorianToJalali("2024-11-03");
rejects(() => dates.localDateTimeToUtc(fall, "01:30:00", "America/New_York"), "DST fall fold is rejected");
rejects(() => dates.canonicalizeRfc3339("2026-09-30T15:00:00-00:00"), "unknown RFC3339 offset is rejected");
rejects(() => dates.canonicalizeRfc3339("2026-09-30T15:00:00.1234Z"), "precision beyond milliseconds is rejected");

console.log(`Due Date frontend regression passed: ${assertions} assertions (process TZ: ${process.env.TZ}).`);
