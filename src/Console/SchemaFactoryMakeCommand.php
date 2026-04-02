<?php

namespace Vyuldashev\LaravelOpenApi\Console;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputOption;

class SchemaFactoryMakeCommand extends GeneratorCommand
{
    protected $name = 'openapi:make-schema';
    protected $description = 'Create a new Schema factory class';
    protected $type = 'Schema';

    protected function buildClass($name)
    {
        $output = parent::buildClass($name);
        $output = str_replace('DummySchema', Str::replaceLast('Schema', '', class_basename($name)), $output);

        if ($model = $this->option('model')) {
            return $this->buildModel($output, $model);
        }

        return $output;
    }

    protected function buildModel($output, $model)
    {
        $appVersion = explode('.', app()::VERSION);
        $namespace = $appVersion[0] >= 8 ? $this->laravel->getNamespace().'Models\\' : $this->laravel->getNamespace();
        $model = Str::start($model, $namespace);

        if (! is_a($model, Model::class, true)) {
            throw new InvalidArgumentException('Invalid model');
        }

        /** @var Model $model */
        $model = app($model);

        $table = $model->getTable();
        $connection = $model->getConnection();
        $schemaManager = SchemaFacade::connection($model->getConnectionName());

        $columns = $schemaManager->getColumns($table);

        $definition = 'return Schema::object(\''.class_basename($model).'\')'.PHP_EOL;
        $definition .= '            ->properties('.PHP_EOL;

        $properties = collect($columns)
            ->map(static function (array $column) {
                $name = $column['name'];
                $default = $column['default'];
                $nullable = $column['nullable'];
                $typeName = $column['type_name'];

                $format = match ($typeName) {
                    'int', 'integer', 'bigint', 'smallint', 'tinyint', 'mediumint' => 'Schema::integer(%s)->default(%s)',
                    'boolean', 'bool' => 'Schema::boolean(%s)->default(%s)',
                    'date' => 'Schema::string(%s)->format(Schema::FORMAT_DATE)->default(%s)',
                    'datetime', 'timestamp' => 'Schema::string(%s)->format(Schema::FORMAT_DATE_TIME)->default(%s)',
                    'decimal', 'float', 'double' => 'Schema::number(%s)->format(Schema::FORMAT_FLOAT)->default(%s)',
                    default => 'Schema::string(%s)->default(%s)',
                };

                $defaultValue = $nullable ? null : $default;

                if (in_array($typeName, ['int', 'integer', 'bigint', 'smallint', 'tinyint', 'mediumint'])) {
                    $defaultValue = $nullable ? null : (int) $default;
                } elseif (in_array($typeName, ['decimal', 'float', 'double'])) {
                    $defaultValue = $nullable ? null : (float) $default;
                }

                $args = array_map(static function ($value) {
                    if ($value === null) {
                        return 'null';
                    }

                    if (is_numeric($value)) {
                        return $value;
                    }

                    return '\''.$value.'\'';
                }, [$name, $defaultValue]);

                $indentation = str_repeat('    ', 4);

                return sprintf($indentation.$format, ...$args);
            })
            ->implode(','.PHP_EOL);

        $definition .= $properties.PHP_EOL;
        $definition .= '            );';

        return str_replace('DummyDefinition', $definition, $output);
    }

    protected function getStub(): string
    {
        if ($this->option('model')) {
            return __DIR__.'/stubs/schema.model.stub';
        }

        return __DIR__.'/stubs/schema.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\OpenApi\Schemas';
    }

    protected function qualifyClass($name): string
    {
        $name = parent::qualifyClass($name);

        if (Str::endsWith($name, 'Schema')) {
            return $name;
        }

        return $name.'Schema';
    }

    protected function getOptions(): array
    {
        return [
            ['model', 'm', InputOption::VALUE_OPTIONAL, 'The model class schema being generated for'],
            ['force', null, InputOption::VALUE_NONE, 'Create the class even if the factory already exists'],
        ];
    }
}
