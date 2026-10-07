<?php

namespace App\Command\OutgoingPayment;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

//@! start chunk 1 | title=Import dependencies
use OpenPayments\AuthClient;
use OpenPayments\Config\Config;
//@! end chunk 1

/**
 * Class OutgoingPaymentGrantSpentAmounts
 * @package App\Command\OutgoingPayment
 *
 * This command is used to get the spent amounts for the current outgoing payment grant.
 */
class OutgoingPaymentGrantSpentAmounts extends Command
{
    protected static $defaultName = 'op:grant-spent-amounts';

    protected function configure(): void
    {
        $this
            ->setDescription('This command is used to get the spent amounts for the current outgoing payment grant.')
            ->setHelp('This command outputs the debit and receive amounts spent against the outgoing payment grant.')
            ->addArgument(
                'OUTGOING_PAYMENT_GRANT_ACCESS_TOKEN',
                InputArgument::OPTIONAL,
                'Access token for the outgoing payment received from the outgoing payment grant.',
                $_ENV['OUTGOING_PAYMENT_GRANT_ACCESS_TOKEN'] ?? null
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $WALLET_ADDRESS =  $_ENV['WALLET_ADDRESS'];
        $PRIVATE_KEY = $_ENV['PRIVATE_KEY'];
        $KEY_ID = $_ENV['KEY_ID'];
        $OUTGOING_PAYMENT_GRANT_ACCESS_TOKEN = $input->getArgument('OUTGOING_PAYMENT_GRANT_ACCESS_TOKEN');

        //@! start chunk 2 | title=Initialize Open Payments client
        $config = new Config(
            $WALLET_ADDRESS,
            $PRIVATE_KEY,
            $KEY_ID
        );
        $opClient = new AuthClient($config);
        //@! end chunk 2

        //@! start chunk 3 | title=Get wallet address information
        $wallet = $opClient->walletAddress()->get([
            'url' => $config->getWalletAddressUrl()
        ]);
        //@! end chunk 3

        //@! start chunk 4 | title=Get spent amounts
        $grantSpentAmounts = $opClient->outgoingPayment()->getGrant([
            'url' => $wallet->resourceServer,
            'access_token' => $OUTGOING_PAYMENT_GRANT_ACCESS_TOKEN
        ]);
        //@! end chunk 4

        //@! start chunk 5 | title=Output
        $output->writeln('GRANT_SPENT_DEBIT_AMOUNT: ' . json_encode($grantSpentAmounts->spentDebitAmount?->toArray()));
        $output->writeln('GRANT_SPENT_RECEIVE_AMOUNT: ' . json_encode($grantSpentAmounts->spentReceiveAmount?->toArray()));
        //@! end chunk 5

        return Command::SUCCESS;
    }
}
