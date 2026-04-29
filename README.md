# AR Design Reporting - Products Module

Samostatný doplnkový plugin pre `ar-design-reporting`.

Tento adresár je pripravený ako **vlastný repozitár** pre produktový reporting modul. Release a aktualizácie pluginu už nemajú byť viazané na repozitár core pluginu.

## Čo robí

- Vkladá sekciu **Produktový reporting** do dashboardu reportingu.
- Zobrazuje:
  - najpredávanejšie produkty,
  - najvyššie skladové zásoby,
  - predaj v čase (graf),
  - historický sklad produktu (graf + tabuľka).
- Pridáva exporty:
  - `Exportovať produkty (XLSX)`
  - `Exportovať históriu skladu produktu (XLSX)`
- Vytvára vlastnú tabuľku `wp_ard_product_stock_history` a denne robí snapshot zásob.

## Inštalácia

1. Zabaľte celý priečinok `ar-design-reporting-products-module` do ZIP.
2. Vo WordPress: `Pluginy -> Pridať nový -> Nahrať plugin`.
3. Aktivujte plugin.
4. Otvorte `AR Design Reporting` dashboard.

## Release a update kanál

- kanonický GitHub repozitár: `Arpad70/woocommerce_ar-design-reporting-products-module`
- release asset pre updater: `ar-design-reporting-products-module.zip`
- verzia pluginu sa riadi súborom `VERSION`

GitHub Actions workflow v `.github/workflows/release.yml` balí ZIP priamo z koreňa tohto pluginu a publikuje ho ako samostatný release.

Podrobný postup pre prvý standalone release aj pre ďalšie verzie je v `RELEASE.md`.

Produkčný update checklist a rollback postup je v `PRODUCTION_UPDATE_CHECKLIST.md`.

## Odinštalácia

- Deaktivácia pluginu zastaví cron snapshoty.
- Odinštalácia pluginu odstráni tabuľku histórie skladu.

## Požiadavky

- Aktívny hlavný plugin `ar-design-reporting`.
- WooCommerce.

## Poznámka k oddeleniu od core pluginu

- core plugin `ar-design-reporting` má zostať vo vlastnom repozitári,
- products modul má vlastný release cyklus,
- upgrady vo WordPresse sa majú načítavať z release tohto samostatného repozitára.
