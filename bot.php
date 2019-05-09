<?php

require 'vendor/autoload.php';

use React\EventLoop\Factory;
use BotMan\BotMan\BotManFactory;
use BotMan\BotMan\BotMan;
use BotMan\BotMan\Drivers\DriverManager;
use BotMan\Drivers\Slack\SlackRTMDriver;
use Goutte\Client as GClient;
use \Symfony\Component\DomCrawler\Crawler;

class Grillbiffen
{
    const GRILLBIFF_IDENTIFIER = 'Grillbiff med';

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
     */
    protected function getTodaysDaySwedish()
    {
        return $this->swedishDays[date('N')-1];
    }

    /**
     * Get the node containing today's lunch
     * @return Crawler
     */
    protected function getTodaysNode()
    {
        $todayDay = $this->getTodaysDaySwedish();
        $client = new GClient();
        $crawler = $client
            ->setHeader('User-Agent', "Mozilla/5.0 (Windows NT 5.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2272.101 Safari/537.36")
            ->request('GET', $this->getConfig()['url']);

        $foundToday = false;
        $nodeValues = $crawler->filter('div.restaurangsida_lunchbox')->first()->children()
            ->each(function (Crawler $node) {
                return [trim($node->attr('class')), $node];
            });

        foreach ($nodeValues as $nodeArr) {
            /** @var Crawler $node */
            $class = $nodeArr[0];
            $node = $nodeArr[1];
            $text = $node->text();
            // Search for correct day
            if (!$foundToday && $class === 'restaurangsida_dagrubrik') {
                if (strpos($text, $todayDay) === 0) {
                    $foundToday = true;
                    continue;
                } else {
                    continue;
                }
            }

            if ($foundToday && $class === 'rest_box_lunch') {
                return $node;
            }
        }
    }

    /**
     * Get the wanted strings from the crawler and return it readily formatted
     * @param Crawler $node
     * @return string
     */
    protected function formatHTML(Crawler $node)
    {
        $result = [];
        $nodes = $node->filter('tr')
            ->each(function (Crawler $node1) {
            return $node1;
        });
        /** @var Crawler $node2 */
        foreach ($nodes as $node2) {
            $text = $node2->children()->first()->text();
            // Skip whole day if one of these are found
            if (in_array($text,['Ingen lunchservering'])) {
                return '';
            }
            // Skip these lines
            if (in_array($text,['Lunchalternativ','Stående lunchbord','Salladsbord'])) {
                continue;
            }
            $result[] = $text;
        }

        $result = $this->lookForGrillbiff($result);

        return implode("\n", $result);
    }

    /**
     * Insert an appropriate amount of emojis into the parsed message
     * if Grillbiff is found
     *
     * @param array $result
     *
     * @return array
     */
    protected function lookForGrillbiff(array $result) : array
    {
        $grillbiffFound = false;

        foreach ($result as $text) {
            if (strpos($text, self::GRILLBIFF_IDENTIFIER) !== false) {
                $grillbiffFound = true;
                break;
            }
        }

        if ($grillbiffFound) {
            $str = str_repeat(':grillbiffidag:', 10);
            array_unshift($result, $str);
            $result[] = $str;
        }

        return $result;
    }

    /**
     * Send message to configured slack channels
     *
     * @param $message
     * @throws \BotMan\BotMan\Exceptions\Base\BotManException
     */
    protected function sendSlackMessage($message)
    {
        DriverManager::loadDriver(SlackRTMDriver::class);
        $loop = Factory::create();
        /** @var BotMan $botman */
        $botman = BotManFactory::createForRTM($this->getConfig()['config'], $loop);
        $botman->say($message, $this->getConfig()['to']);
        //$loop->run();
    }

    public function run(){
        $node = $this->getTodaysNode();
        $formatted = $this->formatHTML($node);
        if ($formatted) {
            print "Sending slack message $formatted\n";
            $this->sendSlackMessage($formatted);
        }else {
            print "Not sending slack message\n";
        }
    }
}

$grillbiffen = New Grillbiffen;
$grillbiffen->run();