# Production Update Checklist

## Účel

Tento checklist slúži pre **prvý standalone release** produktového modulu aj pre ďalšie produkčné aktualizácie.

Použitie:

- pred prvým release z nového repozitára,
- pred aktualizáciou pluginu cez WordPress administráciu,
- pri kontrole rollback pripravenosti.

---

## Pred release

- [ ] verzia v `VERSION` je správna
- [ ] `Version:` v `ar-design-reporting-products-module.php` zodpovedá `VERSION`
- [ ] `ARD_REPORTING_PRODUCTS_MODULE_VERSION` zodpovedá `VERSION`
- [ ] `Update URI` smeruje na `Arpad70/woocommerce_ar-design-reporting-products-module`
- [ ] `ARD_REPORTING_PRODUCTS_MODULE_REPOSITORY` smeruje na `Arpad70/woocommerce_ar-design-reporting-products-module`
- [ ] `php scripts/verify-version-consistency.php` prejde bez chyby
- [ ] `bash scripts/build-plugin.sh` vytvorí ZIP bez chýb
- [ ] GitHub repo existuje a je dostupné
- [ ] branch `main` je pushnutý
- [ ] tag `v<version>` bol pushnutý
- [ ] GitHub Release obsahuje asset `ar-design-reporting-products-module.zip`

---

## Pred produkčným update

- [ ] vytvorená záloha databázy
- [ ] vytvorená záloha adresára `wp-content/plugins/ar-design-reporting-products-module`
- [ ] potvrdené, že core plugin `ar-design-reporting` ostáva aktívny
- [ ] potvrdené, že WooCommerce je aktívny
- [ ] administrátor vie, kedy bude update vykonaný
- [ ] existuje prístup k serveru / SSH pre prípad manuálneho rollbacku

---

## Po update vo WordPresse

- [ ] plugin `ar-design-reporting-products-module` zostal aktívny
- [ ] v administrácii sa otvorí stránka `AR Design Reporting`
- [ ] sekcia `Produktový reporting` sa renderuje bez PHP chyby
- [ ] tabuľka najpredávanejších produktov sa načíta
- [ ] tabuľka skladových zásob sa načíta
- [ ] graf predajov sa zobrazí
- [ ] drill-down produktu funguje
- [ ] export `Exportovať produkty (XLSX)` funguje
- [ ] export `Exportovať históriu skladu produktu (XLSX)` funguje
- [ ] nie sú nové fatálne chyby v PHP logu

---

## Cron a dáta

- [ ] cron hook `ard_reporting_products_capture_stock_daily` je naďalej naplánovaný
- [ ] tabuľka `wp_ard_product_stock_history` stále existuje
- [ ] história skladu zostala zachovaná

Poznámka: presný prefix tabuľky sa riadi `$wpdb->prefix`, takže v inej inštalácii nemusí byť prefix `wp_`.

---

## Rollback plán

Ak update zlyhá alebo sa správanie zhorší:

1. deaktivovať products modul,
2. obnoviť zálohovaný adresár pluginu,
3. ak treba, obnoviť databázu zo zálohy,
4. znovu aktivovať plugin,
5. overiť dashboard a exporty,
6. skontrolovať PHP log a GitHub Release asset.

Ak sa problém týka len updater kanála, ale nie runtime pluginu, dočasne je možné:

- ponechať aktuálnu produkčnú verziu pluginu,
- opraviť GitHub Release alebo asset,
- opakovať update až po potvrdení korektného release balíka.

---

## Sign-off

- [ ] release technicky overený
- [ ] produkčný update overený
- [ ] rollback pripravený
- [ ] nový standalone update kanál potvrdený
