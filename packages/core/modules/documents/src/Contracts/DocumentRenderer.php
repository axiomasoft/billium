<?php

declare(strict_types=1);

namespace Billium\Documents\Contracts;

use Billium\Documents\Data\RenderDocumentInput;
use Billium\Documents\Data\RenderedArtifact;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** Host-provided deterministic document rendering SPI invoked strictly outside DB transactions. */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface DocumentRenderer
{
    public function rendererName(): string;

    public function rendererVersion(): string;

    public function render(RenderDocumentInput $input): RenderedArtifact;
}
