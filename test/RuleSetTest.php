<?php

declare(strict_types=1);

namespace PhlyTest\RuleValidation;

use Phly\RuleValidation\Exception\DuplicateRuleKeyException;
use Phly\RuleValidation\Exception\ResultSetFrozenException;
use Phly\RuleValidation\Result;
use Phly\RuleValidation\ResultSet;
use Phly\RuleValidation\Rule;
use Phly\RuleValidation\RuleSet;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;

use function array_key_exists;

class RuleSetTest extends TestCase
{
    public function testCallingAddWithARuleWithANameUsedByAnotherRuleInTheRuleSetRaisesDuplicateKeyException(): void
    {
        $ruleSet = new RuleSet();
        $ruleSet->add($this->createDummyRule('first'));

        $this->expectException(DuplicateRuleKeyException::class);
        $this->expectExceptionMessage('Duplicate validation rule detected for key "first"');
        $ruleSet->add($this->createDummyRule('first'));
    }

    public function testInstantiatingRuleSetWithRulesForSameKeyRaisesDuplicateKeyException(): void
    {
        $rule1 = $this->createDummyRule('first');
        $rule2 = $this->createDummyRule('first');

        $this->expectException(DuplicateRuleKeyException::class);
        $this->expectExceptionMessage('Duplicate validation rule detected for key "first"');
        new RuleSet(...[$rule1, $rule2]);
    }

    public function testGetRuleForKeyReturnsRuleMatchingKey(): void
    {
        $rule1 = $this->createDummyRule('first');
        $rule2 = $this->createDummyRule('second');
        $rule3 = $this->createDummyRule('third');
        $rule4 = $this->createDummyRule('fourth');

        $ruleSet = new RuleSet($rule2, $rule3, $rule1, $rule4);

        $this->assertSame($rule1, $ruleSet->getRuleForKey('first'));
        $this->assertSame($rule2, $ruleSet->getRuleForKey('second'));
        $this->assertSame($rule3, $ruleSet->getRuleForKey('third'));
        $this->assertSame($rule4, $ruleSet->getRuleForKey('fourth'));
    }

    public function testGetRuleForKeyReturnsNullIfNoRuleMatchingKeyFound(): void
    {
        $rule1 = $this->createDummyRule('first');
        $rule2 = $this->createDummyRule('second');

        $ruleSet = new RuleSet($rule1, $rule2);

        $this->assertNull($ruleSet->getRuleForKey('fourth'));
    }

    public function testValidationReturnsAnEmptyResultSetWhenNoRulesPresent(): void
    {
        $ruleSet = new RuleSet();
        $result  = $ruleSet->validate(['some' => 'data']);
        $this->assertCount(0, $result);
    }

    public function testValidationReturnsAPopulatedResultSetWithAKeyMatchingEachRule(): ResultSet
    {
        $data = [
            'first'  => 'string',
            'second' => 'ignored',
            'third'  => 1,
            'fourth' => 'also ignored',
            'fifth'  => [1, 2, 3],
        ];

        $expected = [
            'first' => 'string',
            'third' => 1,
            'fifth' => [1, 2, 3],
        ];

        $ruleSet = new RuleSet();
        $ruleSet->add($this->createDummyRule('first'));
        $ruleSet->add($this->createDummyRule('third'));
        $ruleSet->add($this->createDummyRule('fifth'));

        $result = $ruleSet->validate($data);

        $this->assertTrue($result->isValid());
        $this->assertEquals($expected, $result->getValues());

        return $result;
    }

    #[Depends('testValidationReturnsAPopulatedResultSetWithAKeyMatchingEachRule')]
    public function testResultSetOfValidationIsFrozen(ResultSet $resultSet): void
    {
        $this->expectException(ResultSetFrozenException::class);
        $resultSet->add(Result::forValidValue('anotherInput', 'string'));
    }

    public function testValidationResultSetContainsResultForMissingValueIfARequiredRuleKeyIsNotInTheData(): void
    {
        $data = [
            'first'  => 'string',
            'second' => 'ignored',
            'fourth' => 'also ignored',
            'fifth'  => [1, 2, 3],
        ];

        $expectedValues = [
            'first' => 'string',
            'third' => null,
            'fifth' => [1, 2, 3],
        ];

        $expectedMessages = [
            'third' => 'Missing required value',
        ];

        $ruleSet = new RuleSet();
        $ruleSet->add($this->createDummyRule('first'));
        $ruleSet->add($this->createDummyRule('third', required: true));
        $ruleSet->add($this->createDummyRule('fifth'));

        $result = $ruleSet->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertEquals($expectedValues, $result->getValues());
        $this->assertEquals($expectedMessages, $result->getMessages());
    }

    public function testValidationResultSetContainsResultForValidDefaultValueIfAnOptionalRuleKeyIsNotInTheData(): void
    {
        $data = [
            'first'  => 'string',
            'second' => 'ignored',
            'fourth' => 'also ignored',
            'fifth'  => [1, 2, 3],
        ];

        $expected = [
            'first' => 'string',
            'third' => 1,
            'fifth' => [1, 2, 3],
        ];

        $ruleSet = new RuleSet();
        $ruleSet->add($this->createDummyRule('first'));
        $ruleSet->add($this->createDummyRule('third', default: 1));
        $ruleSet->add($this->createDummyRule('fifth'));

        $result = $ruleSet->validate($data);

        $this->assertTrue($result->isValid());
        $this->assertEquals($expected, $result->getValues());
    }

    public function testValidationAllowsOverridingMissingValueMessageViaExtension(): void
    {
        $ruleSet = new class extends RuleSet {
            private const MISSING_KEY_MAP = [
                'title' => 'Please provide a title',
                // ...
            ];

            public function createMissingValueResultForKey(string $key): Result
            {
                if (array_key_exists($key, self::MISSING_KEY_MAP)) {
                    return Result::forMissingValue($key, self::MISSING_KEY_MAP[$key]);
                }

                return Result::forMissingValue($key);
            }
        };

        $ruleSet->add($this->createDummyRule('title', required: true));
        $result = $ruleSet->validate([]);

        $this->assertFalse($result->isValid());
        $this->assertSame('Please provide a title', $result->getResultForKey('title')->message);
    }

    private function createDummyRule(string $name, mixed $default = null, bool $required = false): Rule
    {
        return new class ($name, $default, $required) implements Rule {
            public function __construct(
                private string $name,
                private mixed $default,
                private bool $required,
            ) {
            }

            public function required(): bool
            {
                return $this->required;
            }

            public function key(): string
            {
                return $this->name;
            }

            public function validate(mixed $value, array $context): Result
            {
                return Result::forValidValue($this->name, $value);
            }

            public function default(): mixed
            {
                return $this->default;
            }
        };
    }
}
