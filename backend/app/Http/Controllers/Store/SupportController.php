<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\SupportTicket;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupportController extends Controller
{
    public function index(Request $request)
    {
        return view('store.support.index', [
            'tickets' => $request->user()?->supportTickets()->latest('updated_at')->get() ?? collect(),
            'orders' => $request->user()?->orders()->whereNotNull('number')->latest()->limit(20)->get() ?? collect(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:200'],
            'category' => ['required', Rule::in(SupportTicket::CATEGORIES)],
            'order' => ['nullable', 'string', Rule::exists('orders', 'public_id')->where('user_id', $request->user()->id)],
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $ticket = $request->user()->supportTickets()->create([
            'subject' => $data['subject'], 'category' => $data['category'], 'status' => 'open',
            'order_id' => $data['order'] ? Order::where('public_id', $data['order'])->value('id') : null,
        ]);
        $ticket->messages()->create(['author_id' => $request->user()->id, 'body' => $data['body']]);

        return redirect()->route('support.show', $ticket)->with('status', __('We received your message.'));
    }

    public function show(Request $request, SupportTicket $ticket)
    {
        abort_unless($ticket->user_id === $request->user()->id, 404);
        $ticket->load(['messages' => fn ($q) => $q->where('is_internal', false)->with('author')]);

        return view('store.support.show', ['ticket' => $ticket]);
    }

    public function reply(Request $request, SupportTicket $ticket)
    {
        abort_unless($ticket->user_id === $request->user()->id, 404);
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        $ticket->messages()->create(['author_id' => $request->user()->id, 'body' => $data['body']]);
        $ticket->update(['status' => 'open']);

        return back();
    }
}
