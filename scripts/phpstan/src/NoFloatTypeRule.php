<?php

declare(strict_types=1);

namespace Billium\Tooling\PhpStan;

use PhpParser\Node;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Property;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Forbids the native `float` type in parameters, return types and properties (D19, REQ-CON-31).
 *
 * @implements Rule<Node>
 */
final class NoFloatTypeRule implements Rule
{
    public function getNodeType(): string
    {
        return Node::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $errors = [];

        if ($node instanceof Node\FunctionLike) {
            $symbol = $this->symbol($node, $scope);
            foreach ($node->getParams() as $param) {
                if ($this->hasFloat($param->type)) {
                    $errors[] = $this->error($symbol.'($'.($param->var instanceof Node\Expr\Variable && is_string($param->var->name) ? $param->var->name : '?').')', $param);
                }
            }
            if ($this->hasFloat($node->getReturnType())) {
                $errors[] = $this->error($symbol.'()', $node);
            }
        }

        if ($node instanceof Property && $this->hasFloat($node->type)) {
            foreach ($node->props as $property) {
                $errors[] = $this->error(($scope->getClassReflection()?->getName() ?? 'class').'::$'.$property->name->toString(), $property);
            }
        }

        return $errors;
    }

    private function symbol(Node\FunctionLike $node, Scope $scope): string
    {
        $class = $scope->getClassReflection()?->getName();

        return match (true) {
            $node instanceof Node\Stmt\ClassMethod => ($class ?? 'class').'::'.$node->name->toString(),
            $node instanceof Node\Stmt\Function_ => $node->name->toString(),
            default => ($class !== null ? $class.'::' : '').'{closure}',
        };
    }

    private function hasFloat(Identifier|Name|ComplexType|null $type): bool
    {
        return match (true) {
            $type instanceof Identifier => $type->toLowerString() === 'float',
            $type instanceof Node\NullableType => $this->hasFloat($type->type),
            $type instanceof Node\UnionType, $type instanceof Node\IntersectionType => array_any(
                $type->types,
                fn (Identifier|Name|Node\IntersectionType $inner): bool => $this->hasFloat($inner),
            ),
            default => false,
        };
    }

    private function error(string $symbol, Node $node): IdentifierRuleError
    {
        return RuleErrorBuilder::message('billium.float-forbidden: '.$symbol)
            ->identifier('billium.floatForbidden')
            ->line($node->getStartLine())
            ->build();
    }
}
