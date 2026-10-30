<?php

declare(strict_types=1);

namespace Elmphant\Tui\Stan;

use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;

/**
 * Named keys are Key enum cases; comparisons with strings such as 'esc' or 'enter' never match.
 *
 * @implements Rule<Expr>
 */
final class KeyNameIsNotTextRule implements Rule
{
    public function getNodeType(): string
    {
        return Expr::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $pairs = match (true) {
            $node instanceof BinaryOp\Identical, $node instanceof BinaryOp\NotIdentical,
            $node instanceof BinaryOp\Equal, $node instanceof BinaryOp\NotEqual => [[$node->left, $node->right], [$node->right, $node->left]],
            $node instanceof Match_ => array_merge(...array_map(
                static fn (Node\MatchArm $arm): array => array_map(static fn (Expr $condition): array => [$node->cond, $condition], $arm->conds ?? []),
                $node->arms,
            )),
            default => [],
        };

        $errors = [];
        foreach ($pairs as [$subject, $text]) {
            $error = self::errorFor($subject, $text, $scope);
            if ($error !== null) {
                $errors[] = $error;
            }
        }

        return $errors;
    }

    private static function errorFor(Expr $subject, Expr $text, Scope $scope): ?IdentifierRuleError
    {
        if (!$text instanceof String_ || !self::isKeyOfAKeyMsg($subject, $scope)) {
            return null;
        }

        $key = Key::NAMES[strtolower($text->value)] ?? null;
        if ($key === null) {
            return null;
        }

        return RuleErrorBuilder::message(sprintf(
            "KeyMsg::\$key is never '%s': a named key arrives as Key::%s.",
            $text->value,
            $key->name,
        ))->identifier('elmphant.key.nameAsText')->line($text->getStartLine())->build();
    }

    private static function isKeyOfAKeyMsg(Expr $subject, Scope $scope): bool
    {
        return $subject instanceof PropertyFetch
            && $subject->name instanceof Identifier
            && $subject->name->name === 'key'
            && (new ObjectType(KeyMsg::class))->isSuperTypeOf($scope->getType($subject->var))->yes();
    }
}
