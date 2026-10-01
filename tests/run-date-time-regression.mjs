import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

// Prove the adapter never falls back to the machine's local timezone.
process.env.TZ = "Pacific/Honolulu";
const root = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const adapterPath = resolve(root, "public/assets/js/utils/date-time.js");
async function loadModule(path) {
    const source = readFileSync(path, "utf8");
    const url = `data:text/javascript;base64,${Buffer.from(source).toString("base64")}`;
    return JSON.stringify(url);
}
const adapterSource = readFileSync(adapterPath, "utf8")
    .replace('"../vendor/jalaali-js.js"', await loadModule(resolve(root, "public/assets/js/vendor/jalaali-js.js")))
    .replace('"../vendor/luxon.js"', await loadModule(resolve(root, "public/assets/js/vendor/luxon.js")));
const dateTime = await import(`data:text/javascript;base64,${Buffer.from(adapterSource).toString("base64")}`);

let assertions = 0;
function check(actual, expected, label) {
    assert.deepEqual(actual, expected, label);
    assertions += 1;
}
function rejects(action, label) {
    assert.throws(action, RangeError, label);
    assertions += 1;
}

check(dateTime.validateJalaliDate("1395-01-23"), true, "ordinary Jalali date validates");
check(dateTime.validateJalaliDate("1394-12-30"), false, "common-year Esfand 30 is invalid");
check(dateTime.validateJalaliDate("1395-12-30"), true, "leap-year Esfand 30 is valid");
check(dateTime.validateJalaliDate("1405-13-01"), false, "month 13 is invalid");
check(dateTime.validateJalaliDate("1405-1-01"), false, "non-padded format is invalid");
check(dateTime.jalaliToGregorian("1395-01-23"), "2016-04-11", "ordinary date converts");
check(dateTime.jalaliToGregorian("1405-01-01"), "2026-03-21", "Nowruz boundary converts");
check(dateTime.jalaliToGregorian("1405-06-31"), "2026-09-22", "last day of a 31-day month converts");
check(dateTime.jalaliToGregorian("1405-07-01"), "2026-09-23", "first day after a month boundary converts");
check(dateTime.jalaliToGregorian("1404-12-29"), "2026-03-20", "Esfand year end converts");
check(dateTime.jalaliToGregorian("1395-12-30"), "2017-03-20", "leap-year Esfand converts");
check(dateTime.gregorianToJalali("2026-03-21"), "1405-01-01", "Nowruz converts in reverse");
check(dateTime.gregorianToJalali("2017-03-20"), "1395-12-30", "leap-year boundary converts in reverse");
check(dateTime.gregorianToJalali(dateTime.jalaliToGregorian("1404-12-29")), "1404-12-29", "Esfand round trip holds");
check(dateTime.jalaliToGregorian(dateTime.gregorianToJalali("2026-09-30")), "2026-09-30", "Gregorian round trip holds");
check(dateTime.validateLocalDateTime("2026-09-30T18:30:00"), true, "local datetime validates");
check(dateTime.validateLocalDateTime("2026-02-30T18:30:00"), false, "impossible Gregorian date is rejected");
check(dateTime.validateLocalDateTime("2026-09-30T24:00:00"), false, "out-of-range hour is rejected");
check(dateTime.validateLocalDateTime("2026-09-30T18:30"), false, "seconds are required");
check(dateTime.formatJalali("1405-07-08"), "۸ مهر ۱۴۰۵", "Jalali formatting is Persian-first");
check(dateTime.formatGregorian("2026-09-30"), "2026-09-30", "Gregorian reference format is stable");
check(dateTime.formatUserDateTime("2026-09-30T15:00:00Z", "Asia/Tehran"), "۸ مهر ۱۴۰۵، ۱۸:۳۰", "user datetime uses saved zone and 24-hour time");
check(dateTime.canonicalizeRfc3339("2026-09-30T18:30:00+03:30"), "2026-09-30T15:00:00Z", "explicit offset canonicalizes to UTC");
check(dateTime.localDateTimeToUtc("1405-07-08", "18:30:00", "Asia/Tehran"), "2026-09-30T15:00:00Z", "Tehran local wall time resolves explicitly");
check(dateTime.localDateTimeToUtc("1405-07-08", "15:00:00", "UTC"), "2026-09-30T15:00:00Z", "UTC wall time resolves explicitly");
check(dateTime.utcToUserLocal("2026-09-30T15:00:00Z", "Asia/Tehran"), {
    gregorianDate: "2026-09-30", localTime: "18:30:00", jalaliDate: "1405-07-08", timeZone: "Asia/Tehran",
}, "UTC instant displays in Tehran with Jalali and Gregorian values");
const tehranLocal = dateTime.utcToUserLocal("2026-09-30T15:00:00Z", "Asia/Tehran");
check(dateTime.localDateTimeToUtc(tehranLocal.jalaliDate, tehranLocal.localTime, tehranLocal.timeZone), "2026-09-30T15:00:00Z", "Tehran display/input round trip preserves the instant");

const summerDate = dateTime.gregorianToJalali("2024-07-10");
check(dateTime.localDateTimeToUtc(summerDate, "09:00:00", "America/New_York"), "2024-07-10T13:00:00Z", "DST-observing zone applies its summer offset");
check(dateTime.utcToUserLocal("2024-07-10T13:00:00Z", "America/New_York").localTime, "09:00:00", "summer instant round-trips to local wall time");
const summerLocal = dateTime.utcToUserLocal("2024-07-10T13:00:00Z", "America/New_York");
check(dateTime.localDateTimeToUtc(summerLocal.jalaliDate, summerLocal.localTime, summerLocal.timeZone), "2024-07-10T13:00:00Z", "DST-zone display/input round trip preserves the instant");
const springDate = dateTime.gregorianToJalali("2024-03-10");
rejects(() => dateTime.localDateTimeToUtc(springDate, "02:30:00", "America/New_York"), "spring-forward gap is rejected");
const fallDate = dateTime.gregorianToJalali("2024-11-03");
rejects(() => dateTime.localDateTimeToUtc(fallDate, "01:30:00", "America/New_York"), "fall-back repeated time is rejected");
check(dateTime.localDateTimeToUtc(springDate, "03:30:00", "America/New_York"), "2024-03-10T07:30:00Z", "valid time after spring-forward resolves");
check(dateTime.localDateTimeToUtc(fallDate, "03:30:00", "America/New_York"), "2024-11-03T08:30:00Z", "valid time after fall-back resolves");
rejects(() => dateTime.jalaliToGregorian("1404-12-30"), "impossible Jalali date is rejected");
rejects(() => dateTime.gregorianToJalali("2026-02-30"), "impossible Gregorian date is rejected");
rejects(() => dateTime.localDateTimeToUtc("1405-07-08", "9:00:00", "Asia/Tehran"), "malformed time is rejected");
rejects(() => dateTime.localDateTimeToUtc("1405-07-08", "09:00:00", "Not/AZone"), "invalid IANA timezone is rejected");
rejects(() => dateTime.canonicalizeRfc3339("2026-09-30T15:00:00"), "timezone-less instant is rejected");
rejects(() => dateTime.canonicalizeRfc3339("2026-02-30T15:00:00Z"), "malformed RFC3339 date is rejected");

console.log(`Date/time regression passed: ${assertions} assertions (process TZ: ${process.env.TZ}).`);
