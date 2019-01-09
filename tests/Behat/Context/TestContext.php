<?php

declare(strict_types=1);

namespace Tests\Behat\Context;

use Behat\Behat\Context\Context;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

final class TestContext implements Context
{
    /**
     * @var string
     */
    private static $workingDir;

    /**
     * @var Filesystem
     */
    private static $filesystem;

    /**
     * @var string
     */
    private static $phpBin;

    /**
     * @var Process
     */
    private $process;

    /**
     * @BeforeFeature
     */
    public static function beforeFeature(): void
    {
        self::$workingDir = sprintf('%s/%s/', sys_get_temp_dir(), uniqid('', true));
        self::$filesystem = new Filesystem();
        self::$phpBin = self::findPhpBinary();
    }

    /**
     * @BeforeScenario
     */
    public function beforeScenario(): void
    {
        self::$filesystem->remove(self::$workingDir);
        self::$filesystem->mkdir(self::$workingDir, 0777);
    }

    /**
     * @AfterScenario
     */
    public function afterScenario(): void
    {
        self::$filesystem->remove(self::$workingDir);
    }

    /**
     * @Given a working Symfony application with SymfonyExtension configured
     */
    public function workingSymfonyApplicationWithExtension(): void
    {
        $this->thereIsConfiguration(<<<'CON'
default:
    extensions:
        FriendsOfBehat\SymfonyExtension:
            kernel:
                class: App\Kernel
CON
        );

        $this->thereIsFile('vendor/autoload.php', sprintf(<<<'CON'
<?php

declare(strict_types=1);

$loader = require '%s';
$loader->addPsr4('App\\', __DIR__ . '/../src/');
$loader->addPsr4('App\\Tests\\', __DIR__ . '/../tests/');

return $loader; 
CON
        , __DIR__ . '/../../../vendor/autoload.php'));

        $this->thereIsFile('src/Kernel.php', <<<'CON'
<?php

namespace App;

use Symfony\Component\HttpKernel\Kernel as HttpKernel;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\Loader\LoaderInterface;

class Kernel extends HttpKernel
{
    public function registerBundles()
    {
        return [
            new \Symfony\Bundle\FrameworkBundle\FrameworkBundle(),
            new \FriendsOfBehat\SymfonyExtension\Bundle\FriendsOfBehatSymfonyExtensionBundle(),
        ];
    }

    public function registerContainerConfiguration(LoaderInterface $loader)
    {
        $loader->load(function (ContainerBuilder $container): void {
            $container->loadFromExtension('framework', [
                'test' => $this->getEnvironment() === 'test',
                'secret' => 'Pigeon',
            ]);
        });
        
        $loader->load(__DIR__ . '/../config/services.yaml');
    }
}
CON
        );

        $this->thereIsFile('config/services.yaml', '');
    }

    /**
     * @Given /^a YAML services file containing:$/
     */
    public function yamlServicesFile($content): void
    {
        $this->thereIsFile('config/services.yaml', (string) $content);
    }

    /**
     * @Given /^a Behat configuration containing(?: "([^"]+)"|:)$/
     */
    public function thereIsConfiguration($content): void
    {
        $mainConfigFile = sprintf('%s/behat.yml', self::$workingDir);
        $newConfigFile = sprintf('%s/behat-%s.yml', self::$workingDir, md5((string) $content));

        self::$filesystem->dumpFile($newConfigFile, (string) $content);

        if (!file_exists($mainConfigFile)) {
            self::$filesystem->dumpFile($mainConfigFile, Yaml::dump(['imports' => []]));
        }

        $mainBehatConfiguration = Yaml::parseFile($mainConfigFile);
        $mainBehatConfiguration['imports'][] = $newConfigFile;

        self::$filesystem->dumpFile($mainConfigFile, Yaml::dump($mainBehatConfiguration));
    }


    /**
     * @Given /^a Behat configuration with the minimal working configuration for MinkExtension$/
     */
    public function thereIsConfigurationWithMinimalWorkingConfigurationForMinkExtension(): void
    {
        $this->thereIsConfiguration(<<<'CON'
default:
    extensions:
        Behat\MinkExtension:
            base_url: "http://localhost:8080/"
            default_session: symfony
            sessions:
                symfony:
                    symfony: ~
CON
        );
    }

    /**
     * @Given /^a (?:.+ |)file "([^"]+)" containing(?: "([^"]+)"|:)$/
     */
    public function thereIsFile($file, $content): void
    {
        self::$filesystem->dumpFile(self::$workingDir . '/' . $file, (string) $content);
    }

    /**
     * @Given /^a feature file containing(?: "([^"]+)"|:)$/
     */
    public function thereIsFeatureFile($content): void
    {
        $this->thereIsFile(sprintf('features/%s.feature', md5(uniqid('', true))), $content);
    }

    /**
     * @When /^I run Behat$/
     */
    public function iRunBehat(): void
    {
        $this->process = new Process(sprintf('%s %s --strict -vvv --no-interaction --lang=en', self::$phpBin, escapeshellarg(BEHAT_BIN_PATH)));
        $this->process->setWorkingDirectory(self::$workingDir);
        $this->process->start();
        $this->process->wait();
    }

    /**
     * @Then /^it should pass$/
     */
    public function itShouldPass(): void
    {
        if (0 === $this->getProcessExitCode()) {
            return;
        }

        throw new \DomainException(
            'Behat was expecting to pass, but failed with the following output:' . PHP_EOL . PHP_EOL . $this->getProcessOutput()
        );
    }

    /**
     * @Then /^it should pass with(?: "([^"]+)"|:)$/
     */
    public function itShouldPassWith($expectedOutput): void
    {
        $this->itShouldPass();
        $this->assertOutputMatches((string) $expectedOutput);
    }

    /**
     * @Then /^it should fail$/
     */
    public function itShouldFail(): void
    {
        if (0 !== $this->getProcessExitCode()) {
            return;
        }

        throw new \DomainException(
            'Behat was expecting to fail, but passed with the following output:' . PHP_EOL . PHP_EOL . $this->getProcessOutput()
        );
    }

    /**
     * @Then /^it should fail with(?: "([^"]+)"|:)$/
     */
    public function itShouldFailWith($expectedOutput): void
    {
        $this->itShouldFail();
        $this->assertOutputMatches((string) $expectedOutput);
    }

    /**
     * @Then /^it should end with(?: "([^"]+)"|:)$/
     */
    public function itShouldEndWith($expectedOutput): void
    {
        $this->assertOutputMatches((string) $expectedOutput);
    }

    /**
     * @param string $expectedOutput
     */
    private function assertOutputMatches($expectedOutput): void
    {
        $pattern = '/' . preg_quote($expectedOutput, '/') . '/sm';
        $output = $this->getProcessOutput();

        $result = preg_match($pattern, $output);
        if (false === $result) {
            throw new \InvalidArgumentException('Invalid pattern given:' . $pattern);
        }

        if (0 === $result) {
            throw new \DomainException(sprintf(
                'Pattern "%s" does not match the following output:' . PHP_EOL . PHP_EOL . '%s',
                $pattern,
                $output
            ));
        }
    }

    /**
     * @return string
     */
    private function getProcessOutput(): string
    {
        $this->assertProcessIsAvailable();

        return $this->process->getErrorOutput() . $this->process->getOutput();
    }

    /**
     * @return int
     */
    private function getProcessExitCode(): int
    {
        $this->assertProcessIsAvailable();

        return $this->process->getExitCode();
    }

    /**
     * @throws \BadMethodCallException
     */
    private function assertProcessIsAvailable(): void
    {
        if (null === $this->process) {
            throw new \BadMethodCallException('Behat proccess cannot be found. Did you run it before making assertions?');
        }
    }

    /**
     * @return string
     *
     * @throws \RuntimeException
     */
    private static function findPhpBinary(): string
    {
        $phpBinary = (new PhpExecutableFinder())->find();
        if (false === $phpBinary) {
            throw new \RuntimeException('Unable to find the PHP executable.');
        }

        return $phpBinary;
    }
}
