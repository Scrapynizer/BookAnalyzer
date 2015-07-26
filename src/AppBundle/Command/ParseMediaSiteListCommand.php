<?php

namespace AppBundle\Command;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DomCrawler\Crawler;

class ParseMediaSiteListCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this->setName('scrap:media:site:list');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $startTime = microtime(true);

        $this->createCache();

        $this->process(100);

        $duration = microtime(true) - $startTime;

        $output->writeln("<info>Execute: $duration</info>");
    }

    private function process($limit)
    {
        $client = new \GuzzleHttp\Client(['base_uri' => 'http://fs.to']);

        if ($last = $this->getLast()) {
            $nextListPath = $this->getNextPage(new Crawler($last));

            // END PAGE
            if (empty($nextListPath)) return;

        } else {
            $nextListPath = '/texts/other/';
        }

        do {
            $html =  $client->get($nextListPath)->getBody()->getContents();

            $this->saveListCache($nextListPath, $html);

            $crawler = new Crawler($html);

            $this->pushPageQueue($this->getPageLinkCollection($crawler));

            $nextListPath = $this->getNextPage($crawler);
        } while (--$limit && $nextListPath);
    }

    private function createCache()
    {
        /* @var $connection Connection */
        $connection = $this->getContainer()->get('doctrine')->getConnection();

        $connection->exec("
            CREATE TABLE IF NOT EXISTS `media_site_list_cache` (
                `id` INT(11) PRIMARY KEY AUTO_INCREMENT,
                `path` VARCHAR(255),
                `value` MEDIUMBLOB,
                UNIQUE KEY (`path`)
            );
        ");

        $connection->exec("
            CREATE TABLE IF NOT EXISTS `media_site_page_queue` (
                `path` VARCHAR(255) PRIMARY KEY,
                `process` TINYINT DEFAULT 0
            );
        ");
    }

    private function getLast()
    {
        /* @var $connection Connection */
        $connection = $this->getContainer()->get('doctrine')->getConnection();

        return $connection
            ->executeQuery("
                SELECT `value` FROM `media_site_list_cache`
                ORDER BY `id` DESC
                LIMIT 1;
            ")
            ->fetchColumn();
    }

    private function getNextPage(Crawler $crawler)
    {
        $nextLinkCrawler = $crawler->filter('.b-pager a.next-link');

        if ($nextLinkCrawler->count()) {
            return $nextLinkCrawler->attr('href');
        }
    }

    private function getPageLinkCollection(Crawler $crawler)
    {
        return $crawler
            ->filter('.b-poster-detail .b-poster-detail__link')
            ->each(function (Crawler $crawler) {
                return $crawler->attr('href');
            });
    }

    private function pushPageQueue(array $pathCollection)
    {
        /* @var $connection Connection */
        $connection = $this->getContainer()->get('doctrine')->getConnection();

        $connection->beginTransaction();

        foreach ($pathCollection as $path) {
            $connection->executeQuery("
                INSERT INTO `media_site_page_queue` (`path`)
                VALUES (:path)
            ", compact('path'));
        }

        $connection->commit();
    }

    private function saveListCache($path, $value)
    {
        /* @var $connection Connection */
        $connection = $this->getContainer()->get('doctrine')->getConnection();
        $connection->executeQuery("
            INSERT INTO `media_site_list_cache` (`path`, `value`)
            VALUES (:path, :value)
        ", compact('path', 'value'));
    }
}