<?php

declare(strict_types=1);

use Flyfinder\Finder;
use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;
use League\Tactician\Setup\QuickStart;
use phpDocumentor\Guides\FileCollector;
use phpDocumentor\Guides\Handlers\ParseDirectoryCommand;
use phpDocumentor\Guides\Handlers\ParseDirectoryHandler;
use phpDocumentor\Guides\Handlers\ParseFileCommand;
use phpDocumentor\Guides\Handlers\ParseFileHandler;
use phpDocumentor\Guides\Metas;
use phpDocumentor\Guides\Parser;
use phpDocumentor\Guides\RestructuredText\MarkupLanguageParser;
use phpDocumentor\Guides\Twig\AssetsExtension;
use phpDocumentor\Guides\Twig\EnvironmentBuilder;
use phpDocumentor\Guides\UrlGenerator;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\AbstractLogger;
use Twig\Environment;

require __DIR__ . '/vendor/autoload.php';

$metas = new Metas([]);
$logger = new class extends AbstractLogger {
    public function log($level, $message, array $context = []): void
    {
        echo $level . ':' . $message . PHP_EOL;
    }
};

$commandbus = QuickStart::create(
    [
        ParseFileCommand::class => new ParseFileHandler(
            $metas,
            $logger,
            new class implements EventDispatcherInterface
            {
                public function dispatch(object $event)
                {
                    return $event;
                }
            },
            new Parser(
                new UrlGenerator(),
                [
                    MarkupLanguageParser::createInstance()
                ]
            )
        )
    ]
);

$parseDirectoryHandler = new ParseDirectoryHandler(
    new FileCollector($metas),
    $commandbus,
);

$sourceFileSystem = new Filesystem(new Local(
    __DIR__ // only render the most standalone guide in the docs (avoiding all tricky docs)
));
$sourceFileSystem->addPlugin(new Finder());

$parseDirCommand = new ParseDirectoryCommand(
    $sourceFileSystem,
    '/docs',
    'rst'
);

$documents = $parseDirectoryHandler->handle($parseDirCommand);

$nodeRenderers = new ArrayObject();
$nodeFactoryCallback = static function () use ($nodeRenderers) {
    return new \phpDocumentor\Guides\NodeRenderers\InMemoryNodeRendererFactory(
        $nodeRenderers,
        new \phpDocumentor\Guides\NodeRenderers\DefaultNodeRenderer()
    );
};

$twigBuilder = new EnvironmentBuilder();
$renderer = new \phpDocumentor\Guides\Renderer(
    [
        new \phpDocumentor\Guides\Renderer\OutputFormatRenderer(
            'html',
            new \phpDocumentor\Guides\NodeRenderers\LazyNodeRendererFactory($nodeFactoryCallback),
            new \phpDocumentor\Guides\Renderer\TemplateRenderer($twigBuilder)
        ),
    ],
    $twigBuilder
);

$nodeRenderers[] = new \phpDocumentor\Guides\NodeRenderers\Html\DocumentNodeRenderer($renderer);
$nodeRenderers[] = new \phpDocumentor\Guides\NodeRenderers\Html\SpanNodeRenderer(
    $renderer,
    new \phpDocumentor\Guides\References\ReferenceResolver([]),
    $logger,
    new UrlGenerator()
);
$nodeRenderers[] = new \phpDocumentor\Guides\NodeRenderers\Html\TableNodeRenderer($renderer);

$config = new \phpDocumentor\Guides\Configuration();
foreach ($config->htmlNodeTemplates() as $node => $template) {
    $nodeRenderers[] = new \phpDocumentor\Guides\NodeRenderers\TemplateNodeRenderer(
        $renderer,
        $template,
        $node
    );
}

$twigBuilder->setEnvironmentFactory(function () use ($logger, $renderer) {
    $twig = new Environment(
        new \Twig\Loader\FilesystemLoader(
            [
                __DIR__  . '/vendor/phpdocumentor/guides/resources/template'
            ]
        )
    );
    $twig->addExtension(new AssetsExtension(
        $logger,
        $renderer,
        new UrlGenerator(),
    ));

    return $twig;
});

$renderDocumentHandler = new \phpDocumentor\Guides\Handlers\RenderDocumentHandler($renderer);
foreach ($documents as $document) {
    echo "Render: " . $document->getFilePath() . PHP_EOL;

    try {
        $renderDocumentHandler->handle(
            new \phpDocumentor\Guides\Handlers\RenderDocumentCommand(
                $document,
                \phpDocumentor\Guides\RenderContext::forDocument(
                    $document,
                    $sourceFileSystem,
                    new Filesystem(new Local(__DIR__ . '/out')),
                    '/example/',
                    $metas,
                    new UrlGenerator(),
                    'html'
                )
            )
        );
    } catch (\Exception $e) {
        echo "Error:" . $e->getMessage() . PHP_EOL;
    }
}
