<?php

namespace App\Command;

use League\Csv\Exception;
use League\Csv\Reader;
use League\Csv\Statement;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class FeeCommand extends Command
{

    public const RATES_URL = 'https://api.exchangeratesapi.io/latest';
    public const RATES_METHOD = 'GET';
    public const BASE_CURRENCY = 'EUR';
    public const TRANSACTION_DEPOSIT = 'deposit';
    public const TRANSACTION_WITHDRAW = 'withdraw';
    public const MERCHANT_BUSINESS_TYPE = 'business';
    public const MERCHANT_PRIVATE_TYPE = 'private';

    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'app:fee';

    public const TRANSACTIONS_KEYS = [
        'date',
        'userId',
        'merchantType',
        'type',
        'amount',
        'currencyCode',
    ];

    /**
     * @var HttpClientInterface
     */
    protected $httpClient;

    /**
     * @var array
     */
    protected static $rates;

    /**
     * @var array
     */
    protected static $fee = [
        'deposit' => 0.0003,
        'withdraw' => [
            'private' => 0.003,
            'business' => 0.005,
        ],
    ];

    protected static $limits = [
        'amountPerWeekTotal' => 1000,
        'maxFreeTransactions' => 3,
    ];

    /**
     * @var array
     */
    protected static $stats = [];

    /**
     * FeeCommand constructor.
     *
     * @param HttpClientInterface $httpClient
     */
    public function __construct(HttpClientInterface $httpClient)
    {
        // best practices recommend to call the parent constructor first and
        // then set your own properties. That wouldn't work in this case
        // because configure() needs the properties set in this constructor
        parent::__construct();

        $this->httpClient = $httpClient;
    }

    /**
     * configure
     */
    protected function configure()
    {

        $this
            // the short description shown while running "php bin/console list"
            ->setDescription('Calculate fees from csv file.')

            // the full command description shown when running the command with
            // the "--help" option
            ->setHelp('This command allows you to calculate fees from input.csv file.')
            ->addArgument(
                'csvFile',
                InputArgument::REQUIRED,
                '/path/to/file/input.csv'
            );
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $records = $this->getRecords($input->getArgument('csvFile'));
        } catch (Exception $e) {
            $output->writeln($e->getMessage());

            return Command::FAILURE;
        }

        foreach ($records as $record) {
            $transaction = array_combine(self::TRANSACTIONS_KEYS, $record);
            $transaction['weekNumber'] = $this->getWeekNumber($transaction['date']);
            $transaction['year'] = $this->getYear($transaction['date']);
            $userStats = self::getUserStats($transaction);
            // check limits and calculate fee
            $fee = number_format(
                round($this->getFee($userStats, $transaction), 2),
                2,
                '.',
                ''
            );
            $output->writeln($fee);
        }

        return Command::SUCCESS;
    }

    /**
     * @param  $csvFile
     *
     * @return iterable
     * @throws Exception
     */
    public function getRecords($csvFile): iterable
    {
        if (!file_exists($csvFile)) {
            throw new \LogicException('There is no file '.$csvFile);
        }

        $csv = Reader::createFromPath($csvFile, 'r');
        $csv->setDelimiter(',');

        $stmt = new Statement();

        return $stmt->process($csv);
    }

    /**
     * @throws \Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     */
    public function setRates(): void
    {
        self::$rates = $this->httpClient->request(self::RATES_METHOD, self::RATES_URL)->toArray();
    }

    /**
     * @param $date
     *
     * @return false|int|string
     */
    public function getYear($date)
    {
        $year = date('Y', strtotime($date));
        $month = date('m', strtotime($date));
        if ((int)$month === 12) {
            $year += 1;
        }

        return $year;
    }

    /**
     * @param $date
     *
     * @return false|string
     */
    public function getWeekNumber($date)
    {
        return date('W', strtotime($date));
    }

    /**
     * @param array $transactionStats
     * @param array $transaction
     *
     * @return float|int
     */
    public function getFee(array $transactionStats, array $transaction)
    {
        if ($transaction['type'] === self::TRANSACTION_DEPOSIT) {
            return $transaction['amount'] * self::$fee['deposit'];
        }

        if ($transaction['merchantType'] === self::MERCHANT_BUSINESS_TYPE
            && $transaction['type'] === self::TRANSACTION_WITHDRAW
        ) {
            return $transaction['amount'] * self::$fee['withdraw']['business'];
        }

        if ($transaction['merchantType'] === self::MERCHANT_PRIVATE_TYPE
            && $transaction['type'] === self::TRANSACTION_WITHDRAW && isset($transactionStats)
        ) {
            $totalTransactions = $transactionStats['totalTransactions'];
            $totalAmount = $transactionStats['totalAmount'];

            if ($totalTransactions <= self::$limits['maxFreeTransactions']
                && $totalAmount <= self::$limits['amountPerWeekTotal']
            ) {
                return 0.00;
            }

            if ($totalTransactions > self::$limits['maxFreeTransactions']) {
                return $transaction['amount'] * self::$fee['withdraw']['private'];
            }

            if ($totalTransactions <= self::$limits['maxFreeTransactions']
                && $totalAmount > self::$limits['amountPerWeekTotal']
            ) {
                if ($totalTransactions > 2) {
                    return $transaction['amount'] * self::$fee['withdraw']['private'];
                }
                $feeAmount = $totalAmount - self::$limits['amountPerWeekTotal'];
                if ($feeAmount > self::$limits['amountPerWeekTotal']) {
                    return $transaction['amount'] * self::$fee['withdraw']['private'];
                }

                return $feeAmount * self::$fee['withdraw']['private'];
            }
        }

        return 0.00;
    }

    /**
     * @param     $amount
     * @param     $currency
     * @param int $precision
     *
     * @return false|float
     */
    public static function _convertCurrencyAmount($amount, $currency, $precision = 2)
    {
        $total = round($amount, $precision);
        if ($currency !== self::BASE_CURRENCY) {
            $rate = self::$rates['rates'][$currency];
            $baseAmount = $amount / $rate;
            $total = round($baseAmount, $precision);
        }

        return $total;
    }

    /**
     * @param array $transaction
     *
     * @return mixed
     */
    public static function getUserStats(array $transaction)
    {
        $transactionBaseAmount = self::_convertCurrencyAmount($transaction['amount'], $transaction['currencyCode']);
        if (!isset(
            self::$stats[$transaction['year']][$transaction['weekNumber']][$transaction['userId']][$transaction['type']]
        )) {
            self::$stats[$transaction['year']][$transaction['weekNumber']][$transaction['userId']][$transaction['type']]['totalTransactions'] = 1;
            self::$stats[$transaction['year']][$transaction['weekNumber']][$transaction['userId']][$transaction['type']]['totalAmount'] = $transactionBaseAmount;
        } else {
            self::$stats[$transaction['year']][$transaction['weekNumber']][$transaction['userId']][$transaction['type']]['totalTransactions'] += 1;
            self::$stats[$transaction['year']][$transaction['weekNumber']][$transaction['userId']][$transaction['type']]['totalAmount'] += $transactionBaseAmount;
        }

        return self::$stats[$transaction['year']][$transaction['weekNumber']][$transaction['userId']][$transaction['type']];
    }
}
