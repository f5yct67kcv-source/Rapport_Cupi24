// Minimaler Service Worker -- nur damit die App auf Android/Chrome als
// installierbar erkannt wird (Installierbarkeits-Kriterium). Kein Offline-
// Cache: das Tool braucht ohnehin eine Live-Verbindung zum Backend, ein
// Cache wuerde nur veraltete Daten riskieren.
self.addEventListener('fetch', () => {});
