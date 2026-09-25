/**
 * A product's stock at every location: the levels that exist, and a row of
 * zeros (version 0) for each location that has none yet — which is where an
 * adjustment creates one. In the order the locations come.
 */
export function stockPerLocation(locations, levels) {
  const byLocation = new Map(levels.map((level) => [level.locationId, level]));

  return locations.map((location) => {
    const level = byLocation.get(location.id);

    return {
      location,
      onHand: level?.onHand ?? 0,
      reserved: level?.reserved ?? 0,
      available: level?.available ?? 0,
      version: level?.version ?? 0,
      updatedAt: level?.updatedAt ?? null,
    };
  });
}
