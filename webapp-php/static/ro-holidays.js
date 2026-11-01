/*
 * Legal holidays for the employer posting builder (UX-EMP-01).
 *
 * Source: Codul muncii (Legea 53/2003), art. 139 alin. (1), text updated to
 * 2026-11-01 on rubinian.com, checked 2026-11-02. The list of art. 139(1):
 *   1-2 Jan, 6 Jan, 7 Jan (Legea 52/2023), 24 Jan, Vinerea Mare, Easter day 1-2,
 *   1 May, 1 Jun, Rusalii day 1-2, 15 Aug, 30 Nov, 1 Dec, Christmas day 1-2.
 * Orthodox Easter: 2026-04-12, 2027-05-02. Vinerea Mare is the Friday before.
 * Rusalii is Easter + 49 days (Sunday) and the Monday after.
 * A holiday on a weekend adds no day off; the builder counts only Mon-Fri.
 *
 * Only 2026 and 2027 are listed. For other years the builder counts weekends
 * only and shows a warning. Add a year here, with its Easter dates, to extend it.
 */
(function () {
  'use strict';
  var FIXED = ['01-01', '01-02', '01-06', '01-07', '01-24', '05-01', '06-01', '08-15', '11-30', '12-01', '12-25', '12-26'];
  var MOVABLE = {
    2026: ['2026-04-10', '2026-04-12', '2026-04-13', '2026-05-31', '2026-06-01'],
    2027: ['2027-04-30', '2027-05-02', '2027-05-03', '2027-06-20', '2027-06-21']
  };
  var SET = Object.create(null);
  Object.keys(MOVABLE).forEach(function (y) {
    FIXED.forEach(function (md) { SET[y + '-' + md] = true; });
    MOVABLE[y].forEach(function (d) { SET[d] = true; });
  });
  window.RoHolidays = {
    years: Object.keys(MOVABLE).map(Number),
    isHoliday: function (iso) { return SET[iso] === true; },
    knownYear: function (y) { return MOVABLE[y] !== undefined; },
    list: function (y) {
      return Object.keys(SET).filter(function (d) { return d.slice(0, 4) === String(y); }).sort();
    }
  };
})();
