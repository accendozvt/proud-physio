// Canonical URL normalisation: force apex domain, strip /index.html, and
// redirect legacy .html URLs to their clean extensionless equivalent.
(function () {
  try {
    var l = window.location;
    var host = l.hostname;
    var path = l.pathname;
    var changed = false;
    if (host.indexOf('www.') === 0) { host = host.slice(4); changed = true; }
    if (/\/index\.html$/i.test(path)) {
      path = path.replace(/\/index\.html$/i, '/');
      changed = true;
    } else if (/\.html$/i.test(path)) {
      path = path.replace(/\/?[^\/]*\.html$/i, function (m) {
        return m.replace(/\.html$/i, '/');
      });
      changed = true;
    }
    if (changed) {
      l.replace(l.protocol + '//' + host + path + l.search + l.hash);
    }
  } catch (e) {}
})();
