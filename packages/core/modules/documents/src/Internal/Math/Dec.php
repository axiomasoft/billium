<?php

declare(strict_types=1);

namespace Billium\Documents\Internal\Math;

use InvalidArgumentException;
use LogicException;
use Packman\Attributes\Internal;

#[Internal]
final readonly class Dec
{
    /** @param numeric-string $unscaled */
    private function __construct(public string $unscaled, public int $scale) {}

    public static function parse(string $value): self
    {
        if (! is_numeric($value) || preg_match('/^(-?)(0|[1-9][0-9]*)(?:\.([0-9]+))?$/D', $value, $matches) !== 1) {
            throw new InvalidArgumentException('Invalid decimal: '.$value);
        }
        $fraction = $matches[3] ?? '';
        $digits = ltrim($matches[2].$fraction, '0');
        $digits = $digits === '' ? '0' : $digits;

        $unscaled = $matches[1] === '-' && $digits !== '0' ? '-'.$digits : $digits;
        if (! is_numeric($unscaled)) {
            throw new InvalidArgumentException('Invalid decimal digits');
        }

        return new self($unscaled, strlen($fraction));
    }

    public function rescale(int $scale): self
    {
        if ($scale < $this->scale) {
            throw new LogicException('Lossy rescale');
        }

        return new self(bcmul($this->unscaled, bcpow('10', (string) ($scale - $this->scale), 0), 0), $scale);
    }

    public function add(self $other): self
    {
        $scale = max($this->scale, $other->scale);

        return new self(bcadd($this->rescale($scale)->unscaled, $other->rescale($scale)->unscaled, 0), $scale);
    }

    public function sub(self $other): self
    {
        $scale = max($this->scale, $other->scale);

        return new self(bcsub($this->rescale($scale)->unscaled, $other->rescale($scale)->unscaled, 0), $scale);
    }

    public function mul(self $other): self
    {
        return new self(bcmul($this->unscaled, $other->unscaled, 0), $this->scale + $other->scale);
    }

    public function cmp(self $other): int
    {
        $scale = max($this->scale, $other->scale);

        return bccomp($this->rescale($scale)->unscaled, $other->rescale($scale)->unscaled, 0);
    }

    public function sign(): int
    {
        return bccomp($this->unscaled, '0', 0);
    }

    public function isZero(): bool
    {
        return $this->sign() === 0;
    }

    /** @return numeric-string */
    public function toMinor(int $exponent, string $mode): string
    {
        $num = bcmul($this->unscaled, bcpow('10', (string) $exponent, 0), 0);
        $den = bcpow('10', (string) $this->scale, 0);

        return IntegerRounding::divide($num, $den, $mode);
    }
}
