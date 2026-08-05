<?php

declare(strict_types=1);

namespace App\Controllers;

use Nimbus\Controller\AbstractController;
use App\Models\OrderModel;
use App\Models\HelixClientInterface;
use App\Models\OrderProjection;

/**
 * Order Gateway & Tracker controller.
 * Handles submission (one write) and tracking (read-only thereafter).
 */
final class OrderController extends AbstractController
{
    private ?OrderModel $orderModel = null;
    private ?HelixClientInterface $helix = null;

    protected function initialize(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        try {
            $this->orderModel = new OrderModel($this->getDb());
            // Instantiate the mock Helix client
            $this->helix = new \App\Models\MockHelixClient($this->getDb());
        } catch (\Throwable $e) {
            error_log('SOE initialization failed: ' . $e->getMessage());
            $this->orderModel = null;
            $this->helix = null;
        }
    }

    /**
     * GET /order — Display the Order Gateway intake form.
     */
    public function gateway(): void
    {
        $config = $this->getConfig();

        $data = [
            'app_name' => $config['app_name'] ?? 'Order Gateway',
            'environments' => ['dev', 'test', 'prod'],
            'sensitivities' => ['public', 'internal', 'confidential', 'restricted'],
            'error' => $_SESSION['order_error'] ?? null
        ];

        unset($_SESSION['order_error']);

        echo $this->render('order/gateway', $data);
    }

    /**
     * POST /order — Submit an order.
     * Validates, creates one Helix ticket (the one write), and stores the order.
     */
    public function submit(): void
    {
        if ($this->orderModel === null || $this->helix === null) {
            $this->error('Database not available', 503);
            return;
        }

        // Parse form data from $_POST or request body
        $data = $this->getRequestData();

        // Fallback to $_POST if getRequestData didn't work
        if (empty($data) && !empty($_POST)) {
            $data = $_POST;
        }

        if (!$this->validate($data, ['app_name', 'environment', 'sensitivity'])) {
            $_SESSION['order_error'] = 'Missing required fields: app_name, environment, sensitivity. Received: ' . json_encode(array_keys($data));
            $this->redirect('/order');
            return;
        }

        try {
            // Build the TicketBlob shape for Helix (DESIGN-DD §5.1)
            $blob = [
                'source' => 'host-build-gateway',
                'orderRef' => null, // Will be assigned after order creation
                'summary' => $data['app_name'] . ' — ' . ucfirst($data['environment']),
                'app' => $data['app_name'],
                'environment' => $data['environment'],
                'sensitivity' => $data['sensitivity'],
                'expectedUsers' => (int) ($data['expected_users'] ?? 1),
                'needBy' => $data['need_by'] ?? null,
                'resolvedProfile' => 'standard',
                'requirementsRecord' => $data['context'] ?? '',
                'queues' => [
                    'Virtualization',
                    'Linux Engineering',
                    'Security Engineering',
                    'Directory Services',
                    'PKI / Certificate',
                    'Network Security',
                    'Service Desk'
                ]
            ];

            // Create the Helix ticket (the ONE write — golden rule #1)
            $ticketResult = $this->helix->createTicket($blob);
            $ticketId = $ticketResult['id'] ?? null;

            if (!$ticketId) {
                throw new \Exception('Failed to create Helix ticket');
            }

            // Store the order record
            $orderRef = $this->orderModel->createOrder($data, $ticketId);

            // Redirect to the tracker to show the new order
            $this->redirect('/tracker/' . $orderRef);
        } catch (\Exception $e) {
            error_log('Order submission failed: ' . $e->getMessage());
            $_SESSION['order_error'] = 'Order submission failed: ' . $e->getMessage();
            $this->redirect('/order');
        }
    }

    /**
     * GET /tracker — Display the tracker/switcher (list of orders).
     */
    public function trackerList(): void
    {
        if ($this->orderModel === null || $this->helix === null) {
            $this->error('Database not available', 503);
            return;
        }

        try {
            $orders = $this->orderModel->getAllOrders();
            $config = $this->getConfig();

            $data = [
                'app_name' => $config['app_name'] ?? 'Order Tracker',
                'orders' => $orders
            ];

            echo $this->render('order/tracker_list', $data);
        } catch (\Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    /**
     * GET /tracker/{ref} — Display detailed tracker view for a specific order.
     */
    public function trackerDetail(string $ref): void
    {
        if ($this->orderModel === null || $this->helix === null) {
            $this->error('Database not available', 503);
            return;
        }

        try {
            $order = $this->orderModel->getOrder($ref);
            if (!$order) {
                $this->error("Order {$ref} not found", 404);
                return;
            }

            $ticket = $this->helix->getTicket($order['helix_ticket_ref']);
            $projection = OrderProjection::project($ticket, $order);
            $config = $this->getConfig();

            $data = array_merge($projection, [
                'app_name' => $config['app_name'] ?? 'Order Tracker',
                'orders' => $this->orderModel->getAllOrders()
            ]);

            echo $this->render('order/tracker_detail', $data);
        } catch (\Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    /**
     * GET /api/orders/{ref} — JSON API for tracker polling.
     */
    public function apiTrackerDetail(string $ref): void
    {
        if ($this->orderModel === null || $this->helix === null) {
            $this->error('Database not available', 503);
            return;
        }

        try {
            $order = $this->orderModel->getOrder($ref);
            if (!$order) {
                $this->error("Order {$ref} not found", 404);
                return;
            }

            $ticket = $this->helix->getTicket($order['helix_ticket_ref']);
            $projection = OrderProjection::project($ticket, $order);

            $this->json([
                'success' => true,
                'data' => $projection
            ]);
        } catch (\Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    /**
     * POST /order/{ref}/advance — Dev-only: simulate Helix progression.
     * Only enabled when app_env is not 'production'.
     */
    public function advanceTicket(string $ref): void
    {
        $config = $this->getConfig();
        $appEnv = $config['app_env'] ?? 'demo';

        if ($appEnv === 'production') {
            $this->error('Not available in production', 403);
            return;
        }

        if ($this->orderModel === null || $this->helix === null) {
            $this->error('Database not available', 503);
            return;
        }

        try {
            $order = $this->orderModel->getOrder($ref);
            if (!$order) {
                $this->error("Order {$ref} not found", 404);
                return;
            }

            $ticketId = $order['helix_ticket_ref'];
            if (!$ticketId || !($this->helix instanceof \App\Models\MockHelixClient)) {
                $this->error('Cannot advance: not a mock ticket', 400);
                return;
            }

            $this->helix->advance($ticketId);

            $this->success(null, "Advanced ticket {$ticketId}");
        } catch (\Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }
}
