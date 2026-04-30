# Laravel DataTable Kit - Detayli Kullanim Dokumani

Bu dokuman, `zyd-labs/laravel-datatable-kit` paketini Laravel + PrimeVue projelerinde uretim seviyesinde kullanmak icin adim adim rehber sunar.

## 1) Paket Ne Yapar?

Paket, DataTable isteklerinde tekrar eden su sorumluluklari merkezi hale getirir:

- global arama
- alan bazli filtreleme (`filters[field][constraints][]`)
- iliskili alanlarda arama/filtre/siralama (`relation.column`)
- sayfalama (`first`, `rows`)
- export (`xlsx` varsayilan, custom exporter destekli)

Sonuc sozlesmesi:

- `data`: mevcut sayfadaki kayitlar
- `total`: filtrelenmis toplam kayit
- `queries`: `app.debug=true` iken uretilen SQL loglari

## 2) Kurulum

### 2.1 Composer

```bash
composer require zyd-labs/laravel-datatable-kit
```

> Not: Bu repo paket kaynagi oldugu icin gelistirme asamasinda `path repository` ile de kullanilabilir.

### 2.2 Opsiyonel Config Publish

Laravel projelerinde artisan komutlarini Sail ile calistirin:

```bash
./vendor/bin/sail artisan vendor:publish --tag=datatable-config
```

`config/datatable.php` su an minimaldir; ileride exporter/context bazli genisletmeye uygundur.

## 3) Mimari Kullanim Modeli

Paketin tavsiye edilen kullanimi:

1. **FormRequest**: Giris payload validasyonu (`DataTableRequest`)
2. **DataTable sinifi**: Query + izinli alan tanimlari
3. **Controller**: Policy kontrolu + `render()` / `export()` yonlendirmesi

Bu model, SRP'yi korur: controller ince kalir, sorgu davranisi DataTable sinifinda toplanir.

## 4) Backend Entegrasyonu

### 4.1 FormRequest

Controller metodunda su request kullanilir:

- `ZydLabs\LaravelDataTableKit\Http\Requests\DataTableRequest`

Desteklenen temel alanlar:

- `first` (int, min 0)
- `rows` (int, 1-1000)
- `sortField` (string|null)
- `sortOrder` (-1, 0, 1)
- `global` (string|null)
- `filters` (PrimeVue filtre yapisi)
- `showDeleted` (bool, uygulama tarafinda yorumlanir)
- `export` (bool)

### 4.2 DataTable Sinifi Olusturma

`AbstractDataTable` sinifini genisletin:

```php
use App\Models\Call;
use Illuminate\Database\Eloquent\Builder;
use ZydLabs\LaravelDataTableKit\DataTable\AbstractDataTable;
use ZydLabs\LaravelDataTableKit\Http\Requests\DataTableRequest;

final class CallDataTable extends AbstractDataTable
{
    protected function query(DataTableRequest $request): Builder
    {
        return Call::query()->with(['company', 'user', 'note']);
    }

    protected function searchable(): array
    {
        return ['id', 'status', 'company.name', 'user.name'];
    }

    protected function filterable(): array
    {
        return ['status', 'created_at', 'company.name', 'user.name'];
    }
}
```

### 4.3 Controller Entegrasyonu

```php
use App\DataTables\CallDataTable;
use App\Models\Call;
use Illuminate\Support\Facades\Gate;
use ZydLabs\LaravelDataTableKit\Http\Requests\DataTableRequest;

public function index(DataTableRequest $request, CallDataTable $table)
{
    Gate::authorize('viewAny', Call::class);

    if ($request->boolean('export')) {
        Gate::authorize('export', Call::class);
        return $table->export($request);
    }

    return $table->render($request);
}
```

## 5) Frontend (PrimeVue) Istek Sozlesmesi

PrimeVue DataTable tarafindan gonderilen payload, backend'de birebir karsilanir.

Ornek payload:

```json
{
  "first": 0,
  "rows": 25,
  "sortField": "company.name",
  "sortOrder": 1,
  "global": "acme",
  "filters": {
    "status": {
      "operator": "and",
      "constraints": [
        { "value": "open", "matchMode": "equals" }
      ]
    },
    "created_at": {
      "operator": "and",
      "constraints": [
        { "value": ["2026-01-01", "2026-01-31"], "matchMode": "between" }
      ]
    }
  }
}
```

Desteklenen `matchMode` degerleri:

- `contains`, `notContains`, `startsWith`, `endsWith`
- `equals`, `notEquals`
- `lt`, `lte`, `gt`, `gte`
- `between`, `in`
- `dateIs`, `dateIsNot`, `dateBefore`, `dateAfter`
- `isNull`, `isNotNull`

## 6) Iliskili Alanlar ve Count Filtreleri

### 6.1 Relation Alanlari

- `company.name` gibi alanlar `BelongsTo`, `HasOne/HasMany`, `BelongsToMany`, `Morph` iliskilerinde desteklenir.
- Paket gerekli yerde `join`, `exists` veya `whereHas` stratejisini otomatik secer.

### 6.2 Count Filtreleri

- `files_count` gibi bir alan `*_count` kalibina uydugunda ilgili relation icin count alt sorgusu calisir.
- Bu ozellik buyuk tablolarda dogru indekslerle birlikte kullanilmalidir.

## 7) Custom Filtreler

Standart matchMode'un disina cikmak icin DataTable sinifinda `customFilters()` ezin:

```php
protected function customFilters(): array
{
    return [
        'has_files' => function (Builder $query, array $constraints, string $boolean): void {
            foreach ($constraints as $index => $constraint) {
                $value = filter_var($constraint['value'] ?? null, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
                if ($value === null) {
                    continue;
                }

                $hasMethod = $boolean === 'or' && $index > 0 ? 'orWhereHas' : 'whereHas';
                $missingMethod = $boolean === 'or' && $index > 0 ? 'orWhereDoesntHave' : 'whereDoesntHave';

                $value ? $query->{$hasMethod}('files') : $query->{$missingMethod}('files');
            }
        },
    ];
}
```

## 8) Export Stratejileri

### 8.1 Varsayilan Export

`DataTablePipeline`, varsayilan olarak `LaravelExcelExporter` ile `.xlsx` indirir.

### 8.2 Ozel Exporter

`DataTableExportable` implement eden bir sinif vererek export davranisini ozellestirebilirsiniz.
Paket icinde `BaseDataTableExport`, kuyruga alma esigi (`queueThreshold`) ve zorunlu kuyruk (`forceQueue`) icin hazir temel sunar.

Kullanim senaryolari:

- buyuk datasetlerde kuyruklu export
- farkli kolon map'leme
- dosya adi stratejisi

## 9) Performans ve Guvenlik Notlari

### 9.1 Performans

- `searchable()` ve `filterable()` listelerini minimum tutun.
- Iliskili alanlarda filtre/siralama yapilan kolonlara indeks ekleyin.
- Buyuk tablolarda `rows` degerini kontrollu sinirlayin (paket zaten 1000 ustunu kirpar).
- `app.debug=false` ortaminda `queries` loglamasi kapanir; production'da bu sekilde calistirin.

### 9.2 Guvenlik

- Her endpointte Policy/Gate kontrolu yapin.
- `searchable()`/`filterable()` whitelist'i disina cikmayin.
- Hassas alanlari (PII vb.) DataTable listesine dahil etmeyin.
- Export aksiyonu icin ayri yetki kontrolu uygulayin.

## 10) Hata Ayiklama ve Gozlemlenebilirlik

- `app.debug=true` iken response icindeki `queries` alani SQL davranisini izlemede yardimcidir.
- Yavas sorgularda once `EXPLAIN` ile plan analizi yapin.
- Gerekirse endpoint bazli sure/latency metrikleri ekleyin.

## 11) Test Stratejisi

En az su senaryolari test edin:

- global arama (hem temel kolon hem relation kolon)
- her kritik `matchMode` kombinasyonu
- siralama (duz kolon + relation kolon)
- yetki yokken 403
- export akisinda yetki + response turu

Repo icinde paket testleri:

```bash
./vendor/bin/sail artisan test --testsuite="Package DataTable"
```

## 12) PrimeVue Tarafi Iyi Uygulamalar (MCP Dokuman Ozetleri)

PrimeVue MCP dokumanlarina gore:

- Laravel kurulumunda `PrimeVue` plugin'i app seviyesinde kaydedin.
- Tema preset'i (`@primeuix/themes`) global tanimlanmali.
- Erisilebilirlikte WCAG 2.1 AA hedefleyin; kontrast, klavye erisimi ve ARIA semantiklerini koruyun.
- DataTable gibi yogun bilesenlerde lazy/server-side veri modeli tercih edin.

Referanslar:

- [PrimeVue Laravel Guide](https://primevue.org/laravel)
- [PrimeVue Accessibility Guide](https://primevue.org/guides/accessibility)

## 13) Uretime Alma Kontrol Listesi

- [ ] Policy/Gate kontrolleri tamam
- [ ] `searchable` / `filterable` whitelist dogrulandi
- [ ] Kritik sorgular `EXPLAIN` ile incelendi
- [ ] Export buyuk veri icin kuyruk stratejisi tanimli
- [ ] Unit/Integration testleri yesil
- [ ] `app.debug=false` production'da dogrulandi

