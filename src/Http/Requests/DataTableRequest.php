<?php

declare(strict_types=1);

namespace ZydLabs\LaravelDataTableKit\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DataTableRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'first' => ['sometimes', 'integer', 'min:0'],
            'rows' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'sortField' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sortOrder' => ['sometimes', 'integer', 'in:-1,0,1'],
            'global' => ['sometimes', 'nullable', 'string', 'max:255'],
            'filters' => ['sometimes', 'array'],
            'filters.*.operator' => ['sometimes', 'in:and,or'],
            'filters.*.constraints' => ['sometimes', 'array'],
            'filters.*.constraints.*.value' => ['nullable'],
            'filters.*.constraints.*.matchMode' => [
                'sometimes',
                'string',
                'in:contains,startsWith,endsWith,equals,notEquals,lt,lte,gt,gte,between,in,dateIs,dateIsNot,dateBefore,dateAfter,isNull,isNotNull,notContains',
            ],
            'showDeleted' => ['sometimes', 'boolean'],
            'export' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'first.integer' => 'İlk kayıt sayısı tam sayı olmalıdır.',
            'first.min' => 'İlk kayıt sayısı 0 veya daha büyük olmalıdır.',
            'rows.integer' => 'Sayfa boyutu tam sayı olmalıdır.',
            'rows.min' => 'Sayfa boyutu en az 1 olmalıdır.',
            'rows.max' => 'Sayfa boyutu en fazla 1000 olabilir.',
            'sortField.string' => 'Sıralama alanı metin olmalıdır.',
            'sortField.max' => 'Sıralama alanı en fazla 100 karakter olabilir.',
            'sortOrder.integer' => 'Sıralama yönü tam sayı olmalıdır.',
            'sortOrder.in' => 'Sıralama yönü -1, 0 veya 1 olmalıdır.',
            'global.string' => 'Arama terimi metin olmalıdır.',
            'global.max' => 'Arama terimi en fazla 255 karakter olabilir.',
            'filters.array' => 'Filtreler dizi olmalıdır.',
            'filters.*.operator.in' => 'Filtre operatörü "and" veya "or" olmalıdır.',
            'filters.*.constraints.array' => 'Filtre kısıtlamaları dizi olmalıdır.',
            'filters.*.constraints.*.matchMode.in' => 'Geçersiz eşleşme modu.',
            'showDeleted.boolean' => 'Silinen kayıtlar parametresi boolean olmalıdır.',
            'export.in' => 'Geçersiz dışa aktarma formatı talep edildi.',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('rows')) {
            $this->merge([
                'rows' => max(1, min(1000, (int) $this->input('rows'))),
            ]);
        }

        if ($this->has('first')) {
            $this->merge([
                'first' => max(0, (int) $this->input('first')),
            ]);
        }
    }
}

