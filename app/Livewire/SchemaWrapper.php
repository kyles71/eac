<?php

namespace App\Livewire;

use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Livewire\Component;

class SchemaWrapper extends Component implements HasSchemas
{
    use InteractsWithSchemas;

    protected array $components = [];
    protected string $classes = '';

    public function mount(?array $components = null, string $classes = '')
    {
        $this->components = $components ?? [];
        $this->classes = $classes ?? '';
    }

    public function schema(Schema $schema): Schema
    {
        return $schema
            ->components($this->components);
    }

    public function render()
    {

        return <<<'HTML'
        <div class="{{ $this->classes }}">
            {{ $this->schema }}
        </div>
        HTML;
    }
}
