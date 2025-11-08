# Laravel DataTable Kit

`zyd-labs/laravel-datatable-kit`, PrimeVue tabanlı sunucu tarafı tablolar için tekrarlayan işleri soyutlayan hafif bir pakettir. Controller veya servis katmanı yalnızca sorgu ve alan tanımlarını yapar; arama, filtre, sıralama, sayfalama ve export davranışları paket tarafından koordine edilir.

## Kurulum

> Repo içerisinde paket `path repository` olarak tanımlanmış durumda:
>
> ```json
> "repositories": [
>   {
>     "type": "path",
>     "url": "packages/zyd-labs/laravel-datatable-kit",
>     "options": { "symlink": true }
>   }
> ]
> ```

Paketi bağımsız kullanmak için:

```bash
composer require zyd-labs/laravel-datatable-kit
```

Paket `DataTableServiceProvider`’ı otomatik olarak kaydeder. Varsayılan konfigürasyonu yayımlamak isterseniz:

```bash
php artisan vendor:publish --tag=datatable-config
```

`config/datatable.php` şu an isteğe bağlı custom filtre sağlayıcılarını tanımlamak için kullanılabilir, ancak varsayılan kullanım için gerekli değildir.

## Hızlı Başlangıç

1. **Form Request**

`ZydLabs\LaravelDataTableKit\Http\Requests\DataTableRequest` PrimeVue parametre şemasına göre doğrulama yapar (`first`, `rows`, `global`, `filters`, `sortField`, `sortOrder` vb.).

2. **DataTable Sınıfı**

`AbstractDataTable` sınıfını genişletin ve yalnızca alan tanımlarını yapın:

```php
use App\Models\Call;
use ZydLabs\LaravelDataTableKit\DataTable\AbstractDataTable;
use ZydLabs\LaravelDataTableKit\Http\Requests\DataTableRequest as DataTableFormRequest;

final class CallDataTable extends AbstractDataTable
{
    protected function query(DataTableFormRequest $request): Builder
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

3. **Controller**

```php
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

## Özellikler

### Arama & Filtreleme

- Global arama: temel tablodaki ve ilişkilerdeki (`relation.column`) alanlar için otomatik join/exists.
- Filtreler: PrimeVue `filters[field][constraints][]` sözleşmesine uygun; **contains**, **startsWith**, **endsWith**, **equals**, **notEquals**, **lt/lte/gt/gte**, **between**, **in**, **isNull**, **isNotNull**, **notContains** matchMode’ları desteklenir.
- Count filtreleri: `relation_count` alanı için otomatik alt sorgu oluşturulur.

### Sıralama

- Doğrudan kolonlar ve ilişkisel alanlar (`company.name`) için sıralama.
- `BelongsToMany`, `HasOne/HasMany`, `Morph` ilişkilerinde alt sorgu veya join destekleri.

### Export

- Varsayılan export `LaravelExcelExporter` (xlsx); `datatable.php` üzerinden alias veya context tanımlayarak özelleştirme yapılabilir.
- `DataTableExportable` sözleşmesi (örn. `BaseDataTableExport`) ile kuyruklu export desteği.
- `{data: [], total: N}` JSON sözleşmesi frontend ile uyumludur.

### Custom Filtreler

- Paket içindeki `FilterApplier`, tablo sınıfının `customFilters()` metodundan dönen closure’ları çağırır.

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

                $value
                    ? $query->{$hasMethod}('files')
                    : $query->{$missingMethod}('files');
            }
        },
    ];
}
```

## Test

Paket testlerini çalıştırmak için (repo bağlamında):

```bash
./vendor/bin/sail artisan test --testsuite="Package DataTable"
```

Kendi projenizde kullanırken Pest/PHPUnit ile DataTable sınıflarınızdaki sorgu ve filtrelerin domain’e uygunluğunu doğrulamanız önerilir.

## Lisans

MIT Lisansı altında yayınlanmıştır. Detaylar için `LICENSE` dosyasını inceleyin.

