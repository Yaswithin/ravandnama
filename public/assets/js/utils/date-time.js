import {
    MAX_JALAALI_YEAR,
    MIN_JALAALI_YEAR,
    isValidJalaaliDate,
    isLeapJalaaliYear,
    jalaaliMonthLength as libraryMonthLength,
    toGregorian,
    toJalaali,
} from "../vendor/jalaali-js.js";
import { DateTime, IANAZone } from "../vendor/luxon.js";

const JALALI_DATE_PATTERN = /^(\d{4})-(\d{2})-(\d{2})$/;
const GREGORIAN_DATE_PATTERN = /^(\d{4})-(\d{2})-(\d{2})$/;
const TIME_PATTERN = /^(\d{2}):(\d{2}):(\d{2})$/;
const LOCAL_DATE_TIME_PATTERN = /^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2})$/;
const RFC3339_PATTERN = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,3})?(?:Z|[+-]\d{2}:\d{2})$/;

const JALALI_MONTHS = [
    "فروردین", "اردیبهشت", "خرداد", "تیر", "مرداد", "شهریور",
    "مهر", "آبان", "آذر", "دی", "بهمن", "اسفند",
];
const PERSIAN_DIGITS = "۰۱۲۳۴۵۶۷۸۹";

function parseDate(value, pattern, label) {
    if (typeof value !== "string") throw new TypeError(`${label} must be a date string.`);
    const match = pattern.exec(value);
    if (!match) throw new RangeError(`${label} must use YYYY-MM-DD format.`);
    return match.slice(1).map(Number);
}

function isValidGregorianDate(year, month, day) {
    if (!Number.isInteger(year) || year < 1 || year > 9999
        || !Number.isInteger(month) || month < 1 || month > 12
        || !Number.isInteger(day) || day < 1) return false;

    const leap = year % 4 === 0 && (year % 100 !== 0 || year % 400 === 0);
    const lengths = [31, leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    return day <= lengths[month - 1];
}

function isValidTime(value) {
    if (typeof value !== "string") return false;
    const match = TIME_PATTERN.exec(value);
    if (!match) return false;
    const [hour, minute, second] = match.slice(1).map(Number);
    return hour <= 23 && minute <= 59 && second <= 59;
}

function requireGregorianDate(value) {
    const [year, month, day] = parseDate(value, GREGORIAN_DATE_PATTERN, "Gregorian date");
    if (!isValidGregorianDate(year, month, day)) throw new RangeError("Gregorian date is not valid.");
    return { year, month, day };
}

function requireJalaliDate(value) {
    const [year, month, day] = parseDate(value, JALALI_DATE_PATTERN, "Jalali date");
    if (!isValidJalaaliDate(year, month, day)) throw new RangeError("Jalali date is not valid.");
    return { year, month, day };
}

function requireTime(value) {
    if (!isValidTime(value)) throw new RangeError("Time must be a valid 24-hour HH:mm:ss value.");
    const [hour, minute, second] = value.split(":").map(Number);
    return { hour, minute, second };
}

function requireTimeZone(timeZone) {
    if (typeof timeZone !== "string" || !IANAZone.isValidZone(timeZone)) {
        throw new RangeError("A valid IANA timezone is required.");
    }
    return timeZone;
}

function pad(value) {
    return String(value).padStart(2, "0");
}

function toDateString(year, month, day) {
    return `${String(year).padStart(4, "0")}-${pad(month)}-${pad(day)}`;
}

function toUtcString(dateTime) {
    const utc = dateTime.toUTC();
    return utc.toISO({ suppressMilliseconds: utc.millisecond === 0, includeOffset: true }).replace("+00:00", "Z");
}

function requireRfc3339Instant(value) {
    if (typeof value !== "string" || !RFC3339_PATTERN.test(value) || value.endsWith("-00:00")) {
        throw new RangeError("Instant must be RFC3339 with seconds and an explicit UTC offset.");
    }

    const dateTime = DateTime.fromISO(value, { setZone: true });
    if (!dateTime.isValid) throw new RangeError("RFC3339 instant is not valid.");
    return dateTime;
}

function toPersianDigits(value) {
    return String(value).replace(/\d/g, (digit) => PERSIAN_DIGITS[Number(digit)]);
}

export function validateJalaliDate(value) {
    if (typeof value !== "string") return false;
    const match = JALALI_DATE_PATTERN.exec(value);
    if (!match) return false;
    const [year, month, day] = match.slice(1).map(Number);
    return isValidJalaaliDate(year, month, day);
}

export function jalaliMonthLength(year, month) {
    if (!Number.isInteger(year) || year < MIN_JALAALI_YEAR || year > MAX_JALAALI_YEAR
        || !Number.isInteger(month) || month < 1 || month > 12) {
        throw new RangeError("Jalali year or month is outside the supported range.");
    }
    return libraryMonthLength(year, month);
}

export function isJalaliLeapYear(year) {
    if (!Number.isInteger(year) || year < MIN_JALAALI_YEAR || year > MAX_JALAALI_YEAR) {
        throw new RangeError("Jalali year is outside the supported range.");
    }
    return isLeapJalaaliYear(year);
}

export function formatJalaliMonth(year, month) {
    jalaliMonthLength(year, month);
    return `${JALALI_MONTHS[month - 1]} ${toPersianDigits(year)}`;
}

export function shiftJalaliMonth(value, amount) {
    const { year, month, day } = requireJalaliDate(value);
    if (!Number.isInteger(amount)) throw new TypeError("Month offset must be an integer.");

    const index = year * 12 + month - 1 + amount;
    const nextYear = Math.floor(index / 12);
    const nextMonth = ((index % 12) + 12) % 12 + 1;
    if (nextYear < MIN_JALAALI_YEAR || nextYear > MAX_JALAALI_YEAR) {
        throw new RangeError("Date is outside the supported Jalali calendar range.");
    }
    return toDateString(nextYear, nextMonth, Math.min(day, jalaliMonthLength(nextYear, nextMonth)));
}

export function shiftJalaliDate(value, dayOffset) {
    if (!Number.isInteger(dayOffset)) throw new TypeError("Day offset must be an integer.");
    const { year, month, day } = requireJalaliDate(value);
    const { gy, gm, gd } = toGregorian(year, month, day);
    const date = new Date(0);
    date.setUTCHours(0, 0, 0, 0);
    date.setUTCFullYear(gy, gm - 1, gd + dayOffset);
    return gregorianToJalali(toDateString(date.getUTCFullYear(), date.getUTCMonth() + 1, date.getUTCDate()));
}

/** Weekday index for a Jalali week: Saturday=0 through Friday=6. */
export function jalaliWeekday(value) {
    const { year, month, day } = requireJalaliDate(value);
    const { gy, gm, gd } = toGregorian(year, month, day);
    return (new Date(Date.UTC(gy, gm - 1, gd)).getUTCDay() + 1) % 7;
}

export function currentDateInTimezone(timeZone, now = new Date()) {
    const zone = requireTimeZone(timeZone);
    if (!(now instanceof Date) || Number.isNaN(now.getTime())) throw new TypeError("A valid instant is required.");
    const local = DateTime.fromJSDate(now, { zone: "utc" }).setZone(zone);
    const gregorianDate = toDateString(local.year, local.month, local.day);
    return { gregorianDate, jalaliDate: gregorianToJalali(gregorianDate) };
}

/** Convert a legacy database wall-clock value to display fields without assigning a timezone. */
export function legacyWallClockToDisplay(value) {
    if (typeof value !== "string") throw new TypeError("Legacy due date must be a string.");
    const match = /^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?$/.exec(value);
    if (!match || !isValidTime(match[2])) throw new RangeError("Legacy due date is not valid.");
    const gregorianDate = match[1];
    requireGregorianDate(gregorianDate);
    const jalaliDate = gregorianToJalali(gregorianDate);
    return {
        kind: "legacy-wall-clock",
        gregorianDate,
        jalaliDate,
        localTime: match[2],
        primary: `${formatJalali(jalaliDate)}، ${toPersianDigits(match[2].slice(0, 5))}`,
        secondary: `${formatGregorianLong(gregorianDate)} · ${match[2].slice(0, 5)}`,
    };
}

export function jalaliToGregorian(value) {
    const { year, month, day } = requireJalaliDate(value);
    const result = toGregorian(year, month, day);
    return toDateString(result.gy, result.gm, result.gd);
}

export function gregorianToJalali(value) {
    const { year, month, day } = requireGregorianDate(value);
    const result = toJalaali(year, month, day);
    if (result.jy < MIN_JALAALI_YEAR || result.jy > MAX_JALAALI_YEAR) {
        throw new RangeError("Date is outside the supported Jalali calendar range.");
    }
    return toDateString(result.jy, result.jm, result.jd);
}

export function validateLocalDateTime(value) {
    if (typeof value !== "string") return false;
    const match = LOCAL_DATE_TIME_PATTERN.exec(value);
    if (!match || !isValidTime(match[2])) return false;
    try {
        requireGregorianDate(match[1]);
        return true;
    } catch {
        return false;
    }
}

/** Convert a Jalali date and local wall-clock time to a canonical UTC instant.
 * Nonexistent DST times and ambiguous repeated times are rejected.
 */
export function localDateTimeToUtc(jalaliDate, localTime, timeZone) {
    const { year, month, day } = requireJalaliDate(jalaliDate);
    const { hour, minute, second } = requireTime(localTime);
    const zone = requireTimeZone(timeZone);
    const { gy, gm, gd } = toGregorian(year, month, day);
    const requested = { year: gy, month: gm, day: gd, hour, minute, second };
    const dateTime = DateTime.fromObject(requested, { zone });

    if (!dateTime.isValid
        || dateTime.year !== gy || dateTime.month !== gm || dateTime.day !== gd
        || dateTime.hour !== hour || dateTime.minute !== minute || dateTime.second !== second) {
        throw new RangeError("Local time does not exist in the selected timezone.");
    }
    if (dateTime.getPossibleOffsets().length !== 1) {
        throw new RangeError("Local time is ambiguous in the selected timezone.");
    }

    return toUtcString(dateTime);
}

/** Convert an RFC3339 instant to local Gregorian/Jalali fields in an explicit IANA timezone. */
export function utcToUserLocal(instant, timeZone) {
    const utcDateTime = requireRfc3339Instant(instant);
    const zone = requireTimeZone(timeZone);
    const local = utcDateTime.setZone(zone);
    const gregorianDate = toDateString(local.year, local.month, local.day);

    return {
        gregorianDate,
        localTime: `${pad(local.hour)}:${pad(local.minute)}:${pad(local.second)}`,
        jalaliDate: gregorianToJalali(gregorianDate),
        timeZone: zone,
    };
}

/** RFC3339 offset input -> canonical UTC string. Fractions are intentionally not accepted. */
export function canonicalizeRfc3339(instant) {
    return toUtcString(requireRfc3339Instant(instant));
}

export function formatJalali(value) {
    const { year, month, day } = requireJalaliDate(value);
    return `${toPersianDigits(day)} ${JALALI_MONTHS[month - 1]} ${toPersianDigits(year)}`;
}

export function formatGregorian(value) {
    requireGregorianDate(value);
    return value;
}

export function formatGregorianLong(value) {
    const { year, month, day } = requireGregorianDate(value);
    const date = new Date(Date.UTC(year, month - 1, day));
    return new Intl.DateTimeFormat("en-GB", {
        day: "numeric",
        month: "long",
        year: "numeric",
        timeZone: "UTC",
    }).format(date);
}

export function formatUserDateTime(instant, timeZone) {
    const local = utcToUserLocal(instant, timeZone);
    return `${formatJalali(local.jalaliDate)}، ${toPersianDigits(local.localTime.slice(0, 5))}`;
}
