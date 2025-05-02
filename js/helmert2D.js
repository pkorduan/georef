function helmert2D(karte, natur) {
  if (karte.length !== natur.length) {
    throw new Error("Es müssen gleich viele Punkte in beiden Listen vorhanden sein.");
  }
  const n = karte.length;
  if (n < 2) {
    throw new Error("Es müssen mindestens 2 Punkte in beiden Listen vorhanden sein.");
  }

  // Schwerpunktkoordinaten im System der Natur
  const ns = [suma(natur, 0) / n, suma(natur, 1) / n];

  // Schwerpunktkoordinaten im System der Karte
  const ks = [suma(karte, 0) / n, suma(karte, 1) / n];

  // Auf den Schwerpunkt bezogenen Koordinaten im System Natur
  const nd = diffs(natur, ns);

  // Auf den Schwerpunkt bezogenen Koordinaten im System Karte
  const kd = diffs(karte, ks);

  // Transformationskonstanten
  const kq = sumq(kd);
  const o = (sump(nd, 0, kd, 1) - sump(nd, 1, kd, 0)) / kq;
  const a = (sump(nd, 0, kd, 0) + sump(nd, 1, kd, 1)) / kq;

  // Transformation in das System der Natur
  const nt = trans(ns, kd, o, a);

  // Restklaffen Natur
  const v = diff(natur, nt);

  return {
    'k' : karte,
    'n' : natur,
    'ks' : ks,
    'ns' : ns,
    'kd' : kd,
    'nd' : nd,
    'o'  : o,
    'a'  : a,
    'nt' : nt,
    'v'  : v
  };
}

function diff(a, b) {
  return a.map((c, i) => [c[0] - b[i][0], c[1] - b[i][1]]);
}

function trans(ns, kd, o, a) {
  return kd.map((p) => [ns[0] + (a * p[0]) + (o * p[1]), ns[1] + (a * p[1]) - (o * p[0])]);
}

/**
 * Function aggregates the sum of the squares of points coordinates.
 * @param {*} pts 
 * @returns 
 */
function sumq(pts) {
  return pts
    .map((p) => (p[0] * p[0]) + (p[1] * p[1]))
    .reduce((accumulator, current) => accumulator + current, 0);
}

function sump(a, ai, b, bi) {
  return a
    .map((current, index) => current[ai] * b[index][bi])
    .reduce((accumulator, current) => accumulator + current, 0);
}

/**
 * Function calculates the differences between the coordinates in a to one coordinate in s.
 * @param {*} a
 * @param {*} s 
 * @returns 
 */
function diffs(a, s) {
  return a.map(
    (current) => { return [ current[0] - s[0], current[1] - s[1]] }
  );
}

/**
 * Function aggregates the values of all elements at index i in an array
 * @param {*} arr 
 * @param {*} i 
 * @returns 
 */
function suma(arr, i) {
  return arr.reduce(
    (accumulator, current) => parseFloat(accumulator) + parseFloat(current[i]),
    0,
  );
}