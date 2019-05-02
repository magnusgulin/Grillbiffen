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
    /**
     * @return array
     */
    protected function getConfig(): array
    {
        // TODO replace ugly hack
        include 'config.php';
        return $config;
    }

    protected function getTodaysNode()
    {
        //TODO dynamic, it is not always Friday
        $todayDay = 'Fredag';
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
                print "Dagens lunch: $text \n";
                return $node;
            }
        }
    }

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
            if (in_array($text,['Lunchalternativ','Stående lunchbord','Salladsbord'])) {
                continue;
            }
            $result[] = $text;
        }
        return implode("\n", $result);
    }

    protected function sendSlackMessage($message)
    {
        DriverManager::loadDriver(SlackRTMDriver::class);
        $loop = Factory::create();
        /** @var BotMan $botman */
        $botman = BotManFactory::createForRTM($this->getConfig()['config'], $loop);
        $botman->say($message, $this->getConfig()['to']);
        $loop->run();
    }

    public function run(){
        $node = $this->getTodaysNode();
        $formatted = $this->formatHTML($node);
        $this->sendSlackMessage($formatted);
    }
}

$grillbiffen = New Grillbiffen;
$grillbiffen->run();