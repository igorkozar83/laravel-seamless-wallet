// Providers send amounts as decimal strings ("12.50"). Parsing them with parseFloat and
// multiplying by 100 gives 1250.0000000000002-style results for some values, so the
// conversion is done on the string itself and never touches floating point.

const DECIMAL = /^(\d{1,9})(?:\.(\d{1,2}))?$/;

export function toCents(amount) {
  const match = DECIMAL.exec(String(amount).trim());

  if (!match) {
    throw new RangeError(`Invalid amount: ${amount}`);
  }

  const [, whole, fraction = ''] = match;
  const cents = Number(whole) * 100 + Number(fraction.padEnd(2, '0'));

  if (cents <= 0) {
    throw new RangeError(`Amount must be positive: ${amount}`);
  }

  return cents;
}

export function fromCents(cents) {
  const sign = cents < 0 ? '-' : '';
  const absolute = Math.abs(cents);

  return `${sign}${Math.floor(absolute / 100)}.${String(absolute % 100).padStart(2, '0')}`;
}
