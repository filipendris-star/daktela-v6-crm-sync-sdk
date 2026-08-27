<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Mapping\Transformer;

use Daktela\CrmSync\Exception\MappingException;

final class TransformerRegistry
{
    /** @var array<string, ValueTransformerInterface> */
    private array $transformers = [];

    public function register(ValueTransformerInterface $transformer): void
    {
        $this->transformers[$transformer->getName()] = $transformer;
    }

    public function get(string $name): ValueTransformerInterface
    {
        return $this->transformers[$name] ?? throw MappingException::unknownTransformer($name);
    }

    public function has(string $name): bool
    {
        return isset($this->transformers[$name]);
    }

    public static function withDefaults(): self
    {
        $registry = new self();
        $registry->register(new DateFormatTransformer());
        $registry->register(new PhoneNormalizeTransformer());
        $registry->register(new BooleanTransformer());
        $registry->register(new StringCaseTransformer());
        $registry->register(new DefaultValueTransformer());
        $registry->register(new CallbackTransformer());
        $registry->register(new PrefixTransformer());
        $registry->register(new StripPrefixTransformer());
        $registry->register(new WrapArrayTransformer());
        $registry->register(new UrlTransformer());
        $registry->register(new JoinTransformer());
        $registry->register(new ValueMapTransformer());

        /** @var CallbackTransformer $callback */
        $callback = $registry->get('callback');
        $callback->registerCallback('strval', fn (mixed $value) => (string) ($value ?? ''));

        return $registry;
    }
}
