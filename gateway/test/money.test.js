import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fromCents, toCents } from '../src/money.js';

test('decimal strings become integer cents without floating-point error', () => {
  assert.equal(toCents('12.50'), 1250);
  assert.equal(toCents('0.29'), 29); // parseFloat('0.29') * 100 === 28.999999999999996
  assert.equal(toCents('1.1'), 110);
  assert.equal(toCents('7'), 700);
  assert.equal(toCents(' 19.99 '), 1999);
});

test('invalid and non-positive amounts are rejected', () => {
  for (const amount of ['', 'abc', '1.234', '-5', '1e3', '0', '0.00']) {
    assert.throws(() => toCents(amount), RangeError, `expected "${amount}" to be rejected`);
  }
});

test('cents format back into the provider decimal format', () => {
  assert.equal(fromCents(1250), '12.50');
  assert.equal(fromCents(5), '0.05');
  assert.equal(fromCents(0), '0.00');
  assert.equal(fromCents(-1999), '-19.99');
});
