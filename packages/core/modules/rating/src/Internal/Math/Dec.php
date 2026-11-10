<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Math;

use InvalidArgumentException;
use LogicException;

final readonly class Dec
{
    /** @param numeric-string $unscaled */
    private function __construct(public string $unscaled, public int $scale) {}

    public static function parse(string $value): self
    {
        if (! is_numeric($value) || preg_match('/^(-?)(0|[1-9][0-9]*)(?:\.([0-9]+))?$/D', $value, $matches) !== 1) {
            throw new InvalidArgumentException('Invalid decimal');
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

    public static function ofUnscaled(string $unscaled, int $scale): self
    {
        if ($scale < 0 || ! is_numeric($unscaled) || preg_match('/^-?[0-9]+$/D', $unscaled) !== 1) {
            throw new InvalidArgumentException('Invalid unscaled decimal');
        }

        return new self(bcadd($unscaled, '0', 0), $scale);
    }

    public function toString(): string
    {
        $negative = str_starts_with($this->unscaled, '-');
        $digits = $negative ? substr($this->unscaled, 1) : $this->unscaled;
        if ($this->scale === 0) {
            return ($negative ? '-' : '').$digits;
        }
        $digits = str_pad($digits, $this->scale + 1, '0', STR_PAD_LEFT);

        return ($negative ? '-' : '').substr($digits, 0, -$this->scale).'.'.substr($digits, -$this->scale);
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

    public function min(self $other): self
    {
        return $this->cmp($other) <= 0 ? $this : $other;
    }

    public function max(self $other): self
    {
        return $this->cmp($other) >= 0 ? $this : $other;
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
        if ($exponent < 0 || $exponent > 4) {
            throw new InvalidArgumentException('Invalid currency exponent');
        }

        return IntegerRounding::divide(
            num: bcmul($this->unscaled, bcpow('10', (string) $exponent, 0), 0),
            den: bcpow('10', (string) $this->scale, 0),
            mode: $mode,
        );
    }
}
