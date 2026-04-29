# Release Process

## Cíl

Tento plugin `ar-design-reporting-products-module` je vydávaný ako **samostatný repozitár** nezávisle od core pluginu `ar-design-reporting`.

GitHub repozitár:

- `Arpad70/woocommerce_ar-design-reporting-products-module`

GitHub Release asset používaný updaterom pluginu:

- `ar-design-reporting-products-module.zip`

---

## Prvý standalone release (migrácia do vlastného repozitára)

### 1. Lokálna kontrola

Pred prvým pushom over:

1. `VERSION`
2. plugin header `Version:` v `ar-design-reporting-products-module.php`
3. konštantu `ARD_REPORTING_PRODUCTS_MODULE_VERSION`
4. `Update URI`
5. konštantu `ARD_REPORTING_PRODUCTS_MODULE_REPOSITORY`
6. workflow `.github/workflows/release.yml`

Kontrola konzistencie:

```bash
php scripts/verify-version-consistency.php
```

### 2. Inicializácia repozitára

Ak ešte neexistuje `.git`, inicializuj repozitár a nastav remote:

```bash
git init -b main
git remote add origin https://github.com/Arpad70/woocommerce_ar-design-reporting-products-module.git
```

### 3. Prvý commit

Odporúčaný prvý commit:

```text
Initialize standalone products module repository and release pipeline
```

### 4. Push do GitHub repozitára

```bash
git add .
git commit -m "Initialize standalone products module repository and release pipeline"
git push -u origin main
```

### 4.1 Presná sekvencia pre prvý standalone release

Ak je lokálny adresár už pripravený, toto je odporúčaná copy-paste sekvencia:

```bash
php scripts/verify-version-consistency.php
bash scripts/build-plugin.sh
git status --short
git add .
git commit -m "Initialize standalone products module repository and release pipeline"
git push -u origin main
git tag v0.3.29
git push origin v0.3.29
```

Pred pushom tagu skontroluj, že:

- repozitár na GitHube už existuje,
- branch `main` je pushnutý bez chyby,
- v GitHub Actions nie je zlyhaný workflow pre `main`.

### 5. Prvý tag a release

Prvý standalone tag musí zodpovedať súboru `VERSION`.

Pre aktuálnu verziu:

```bash
git tag v0.3.29
git push origin v0.3.29
```

Workflow `release.yml` následne:

- overí syntax a konzistenciu verzie,
- vytvorí ZIP balík,
- publikuje GitHub Release,
- priloží asset `ar-design-reporting-products-module.zip`.

### 6. Post-release kontrola

Over v GitHub Releases:

- existuje tag `v0.3.29`,
- release obsahuje asset `ar-design-reporting-products-module.zip`,
- release smeruje na správny commit.

Potom vo WordPresse over:

- plugin ostáva aktívny,
- admin dashboard funguje,
- WordPress pri ďalšom release deteguje update z nového repo kanála.

---

## Bežný release ďalších verzií

### 1. Zvýšenie verzie

Pri novej verzii uprav:

1. `VERSION`
2. `Version:` v `ar-design-reporting-products-module.php`
3. `ARD_REPORTING_PRODUCTS_MODULE_VERSION`

### 2. Overenie

```bash
php scripts/verify-version-consistency.php
```

### 3. Voliteľný lokálny build ZIP

```bash
bash scripts/build-plugin.sh
```

Výstup:

- `build/ar-design-reporting-products-module-<version>.zip`

### 4. Commit a push

```bash
git add .
git commit -m "Release v<version>"
git push origin main
```

### 5. Tag release

```bash
git tag v<version>
git push origin v<version>
```

---

## Deployment do produkcie

Odporúčaný postup:

1. zálohovať databázu,
2. zálohovať `wp-content/plugins/ar-design-reporting-products-module`,
3. nechať WordPress načítať novú verziu z GitHub Releases,
4. vykonať štandardnú aktualizáciu pluginu v administrácii,
5. overiť dashboard, exporty a grafy,
6. skontrolovať, že cron hook `ard_reporting_products_capture_stock_daily` ostal funkčný.

Podrobný produkčný checklist vrátane rollbacku je v `PRODUCTION_UPDATE_CHECKLIST.md`.

---

## Dôležité pravidlá

- products modul sa už **nebaluje z core repozitára** `ar-design-reporting`,
- release ZIP pre updater musí mať názov presne `ar-design-reporting-products-module.zip`,
- tag musí zodpovedať verzii v `VERSION`,
- updater používa GitHub API `releases/latest` pre repozitár `Arpad70/woocommerce_ar-design-reporting-products-module`.
- pri prvom oddelenom release je vhodné kontrolovať produkciu manuálne, kým sa overí nový update kanál.
