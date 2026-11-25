<?php

declare(strict_types=1);

namespace App\Inventory\Presentation\Command;

use App\Inventory\Infrastructure\StockRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * ARCHITECTURE §9: o saldo materializado deve ser reconstruível pelo ledger.
 * Este comando confere stock_balances contra SUM(stock_movements) para todos
 * os tenants e falha (código 1) se houver divergência.
 */
#[AsCommand(name: 'forno:stock:verify', description: 'Confere se os saldos de estoque batem com a soma do ledger')]
final class VerifyStockBalancesCommand extends Command
{
    public function __construct(private readonly StockRepository $repository)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $mismatches = $this->repository->findBalanceMismatches();

        if ([] === $mismatches) {
            $io->success('Todos os saldos conferem com o ledger.');

            return Command::SUCCESS;
        }

        $io->error(\sprintf('%d saldo(s) divergem do ledger.', \count($mismatches)));
        $io->table(array_keys($mismatches[0]), array_map(static fn (array $row) => array_map('strval', $row), $mismatches));

        return Command::FAILURE;
    }
}
