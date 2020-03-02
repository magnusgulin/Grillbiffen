<?php

require 'vendor/autoload.php';

use BotMan\BotMan\Exceptions\Base\BotManException;
use React\EventLoop\Factory;
use BotMan\BotMan\BotManFactory;
use BotMan\BotMan\BotMan;
use BotMan\BotMan\Drivers\DriverManager;
use BotMan\Drivers\Slack\SlackRTMDriver;
use Goutte\Client as GClient;
use \Symfony\Component\DomCrawler\Crawler;

class Grillbiffen
{
    // Note, identifiers should be added lowercase
    public const GRILLBIFF_IDENTIFIERS = ['grillbiff', 'grillipihvi'];
    public const GRILLBIFF_POSTFIX_BEAUTY = ':grillbiffidag:';

    public const BACON_IDENTIFIERS = ['bacon', 'pekoni'];
    public const BACON_POSTFIX_BEAUTY = ':bacon:';
    public const USER_AGENT = 'Mozilla/5.0 (Windows NT 5.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2272.101 Safari/537.36';

    /**
     * Copy the html from the scraped site into local.html and enable this to avoid creating
     * requests to the scraped site.
     */
    public const USE_LOCAL_HTML = false;

    protected $swedishDays = [
        'Måndag',
        'Tisdag',
        'Onsdag',
        'Torsdag',
        'Fredag',
        'Lördag',
        'Söndag',
    ];

    /**
     * @return array
     */
    protected function getConfig(): array
    {
        // TODO replace ugly hack
        include 'config.php';
        return $config;
    }

    /**
     * @return string
     * @deprecated
     */
    protected function getTodaysDaySwedish(): string
    {
        return $this->swedishDays[date('N') - 1];
    }

    /**
     * Returns today's date in format 05.02.2020
     * @return string
     */
    protected function getTodaysDate(): string
    {
        return date('d.m.Y');
    }

    /**
     * Get today's dishes
     * @return array
     */
    protected function getTodaysDishes($url): array
    {
        $todayDate = $this->getTodaysDate();
        $result = [];
        if (self::USE_LOCAL_HTML) {
            $html = file_get_contents('local.html');
            $crawler = new Crawler($html);

        } else {
            $client = new GClient();
            $crawler = $client
                ->setHeader('User-Agent', self::USER_AGENT)
                ->request('GET', $url);
        }

        // Finds the 7 <div class='lunchlist  '> elements
        $nodeValues = $crawler->filter('div.week-lunchlists > div.center')->first()->children()
            ->each(static function (Crawler $node) {
                return $node;
            });

        if (!$nodeValues) {
            throw new \RuntimeException('No days found!');
        }

        /** @var Crawler $node */
        foreach ($nodeValues as $node) {
            //$text = $node->text();
            $text = $node->filter('span.date')->first()->text();


            // Skip if wrong day
            if (strpos($text, $todayDate) !== 0) {
                continue;
            }

            foreach($node->filter('p.name.focus') as $dishnode) {
                /** @var DOMElement $dishnode */
                $dishText =  $dishnode->textContent;
                if ($dishText) {
                    $result[] = $dishText;
                }
            }

            return $result;
        }
    }

    /**
     * Get the wanted strings from the crawler and return it readily formatted
     * @param array $dishes
     * @return string
     */
    protected function formatDishes(array $dishes): string
    {
        $result = [];

        foreach ($dishes as $dishText) {
            // Skip whole day if this is found
            if (stripos($dishText,'Ingen lunchservering')) {
                return '';
            }
            $dishText = $this->beautifyGrillbiff($dishText);
            $dishText = $this->beautifyBacon($dishText);
            $result[] = $dishText;
        }

        return implode("\n", $result);
    }

    /**
     * Insert an appropriate amount of emojis into the parsed message
     * if Grillbiff is found
     *
     * @param string $dishText
     * @return string
     */
    protected function beautifyGrillbiff(string $dishText) : string
    {
        // Check all identifiers by removing them and comparing with original string
        if (str_replace(self::GRILLBIFF_IDENTIFIERS, '',strtolower($dishText)) !== strtolower($dishText)) {
            $dishText .= ' ' . self::GRILLBIFF_POSTFIX_BEAUTY;
        }

        return $dishText;
    }

    /**
     * Insert an appropriate amount of emojis into the parsed message
     * if Bacon is found
     *
     * @param string $dishText
     * @return string
     */
    protected function beautifyBacon(string $dishText): string
    {
        // Check all identifiers by removing them and comparing with original string
        if (str_replace(self::BACON_IDENTIFIERS, '',strtolower($dishText)) !== strtolower($dishText)) {
            $dishText .= ' ' . self::BACON_POSTFIX_BEAUTY;
        }

        return $dishText;
    }

    /**
     * Send message to configured slack channels
     *
     * @param $message
     * @throws BotManException
     */
    protected function sendSlackMessage($message): void
    {
        DriverManager::loadDriver(SlackRTMDriver::class);
        $loop = Factory::create();
        /** @var BotMan $botman */
        $botman = BotManFactory::createForRTM($this->getConfig()['config'], $loop);
        $botman->say($message, $this->getConfig()['to']);
        //$loop->run();
    }

    /**
     * @throws BotManException
     */
    public function run(): void
    {
        // Get swedish dishes
        $dishes = $this->getTodaysDishes($this->getConfig()['url_sv']);
        $formatted = $this->formatDishes($dishes);
        if($formatted) {
            $formatted .= "\n";
        }


        // Get finnish dishes
        $dishes = $this->getTodaysDishes($this->getConfig()['url_fi']);
        $formatted .= $this->formatDishes($dishes);

        if ($formatted) {
            print "Sending slack message $formatted\n";
            $this->sendSlackMessage($formatted);
        } else {
            print "Not sending slack message\n";
        }
    }
}

$grillbiffen = new Grillbiffen;
$grillbiffen->run();
