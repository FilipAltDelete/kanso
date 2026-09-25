/**
 * Orders for the table demo, generated from a fixed seed so every reload (and
 * every shared link) shows the same rows. Amounts are integer minor units,
 * timestamps UTC, as the real API will send them.
 */
export const ORDER_STATUSES = ['pending', 'confirmed', 'allocated', 'picking', 'packed', 'shipped', 'delivered', 'cancelled', 'on_hold'];
export const CHANNELS = ['Webshop', 'Shopify', 'Amazon', 'Store Stockholm'];

const FIRST = ['Anna', 'Erik', 'Maja', 'Lars', 'Åsa', 'Johan', 'Elin', 'Oskar', 'Ingrid', 'Nils', 'Sofia', 'Björn'];
const LAST = ['Andersson', 'Johansson', 'Karlsson', 'Nilsson', 'Eriksson', 'Larsson', 'Öberg', 'Lindqvist', 'Berg', 'Ek'];

function random(seed) {
  let state = seed;

  return () => {
    state = (state * 1664525 + 1013904223) % 4294967296;

    return state / 4294967296;
  };
}

export function demoOrders(count = 1000, seed = 42) {
  const next = random(seed);
  const pick = (list) => list[Math.floor(next() * list.length)];
  const start = Date.UTC(2026, 8, 25, 12, 0, 0);

  return Array.from({ length: count }, (_, index) => {
    const currency = next() < 0.8 ? 'SEK' : 'EUR';

    return {
      id: `ord-${10001 + index}`,
      number: `KO-${10001 + index}`,
      customer: `${pick(FIRST)} ${pick(LAST)}`,
      channel: pick(CHANNELS),
      status: pick(ORDER_STATUSES),
      lines: 1 + Math.floor(next() * 8),
      total: { amount: 4900 + Math.floor(next() * 500000), currency },
      createdAt: new Date(start - index * 37 * 60 * 1000).toISOString(),
    };
  });
}
