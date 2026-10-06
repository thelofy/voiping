<?php

namespace App\Http\Controllers;

use App\Http\Requests\CustomerSearchRequest;
use App\Http\Requests\DeleteCustomerRequest;
use App\Http\Requests\QuickCustomerRequest;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerActiveRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Models\Customer;
use App\Models\User;
use App\Services\CustomerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function __construct(private readonly CustomerService $customers) {}

    public function index(CustomerSearchRequest $request): View
    {
        $result = $this->customers->search(
            $request->validated('name'),
            $request->validated('phone'),
        );

        return view('customers.index', [
            'customers' => $result->customers,
            'phoneUnsearchable' => $result->phoneUnsearchable,
            'filters' => [
                'name' => (string) ($request->validated('name') ?? ''),
                'phone' => (string) ($request->validated('phone') ?? ''),
            ],
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Customer::class);

        return view('customers.create');
    }

    public function store(StoreCustomerRequest $request): RedirectResponse
    {
        $customer = $this->customers->create($request->validated());

        return redirect()
            ->route('customers.show', $customer)
            ->with('status', 'Customer created.');
    }

    public function quickStore(QuickCustomerRequest $request): JsonResponse
    {
        $operator = $request->user();

        if (! $operator instanceof User) {
            abort(403);
        }

        $customer = $this->customers->registerUnknownCaller(
            $operator,
            (string) $request->validated('linkedid'),
            [
                'name' => (string) $request->validated('name'),
                'company_name' => $request->validated('company_name'),
                'notes' => $request->validated('notes'),
                'phones' => [[
                    'raw_number' => (string) $request->validated('phone'),
                ]],
            ],
        );

        return response()->json([
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'company_name' => $customer->company_name,
                'notes' => $customer->notes,
            ],
        ], 201);
    }

    public function show(Customer $customer): View
    {
        $this->authorize('view', $customer);

        $customer->load('phones');

        return view('customers.show', [
            'customer' => $customer,
        ]);
    }

    public function edit(Customer $customer): View
    {
        $this->authorize('update', $customer);

        $customer->load('phones');

        return view('customers.edit', [
            'customer' => $customer,
        ]);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): RedirectResponse
    {
        $this->customers->update($customer, $request->validated());

        return redirect()
            ->route('customers.show', $customer)
            ->with('status', 'Customer updated.');
    }

    public function updateActive(UpdateCustomerActiveRequest $request, Customer $customer): RedirectResponse
    {
        $active = $request->boolean('is_active');
        $this->customers->setActive($customer, $active);

        return redirect()
            ->route('customers.show', $customer)
            ->with('status', $active ? 'Customer activated.' : 'Customer deactivated.');
    }

    public function destroy(DeleteCustomerRequest $request, Customer $customer): RedirectResponse
    {
        $this->customers->delete($customer);

        return redirect()
            ->route('customers.index')
            ->with('status', 'Customer deleted. Call history was kept.');
    }
}
