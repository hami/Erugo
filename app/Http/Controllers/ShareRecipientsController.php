<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use App\Models\Share;
use App\Models\ShareRecipient;
use App\Mail\shareCreatedMail;
use App\Jobs\sendEmail;

/**
 * The people a share was sent to: list, add, edit, remove, and send them the link again,
 * plus suggestions from past shares when adding new ones.
 */
class ShareRecipientsController extends Controller
{
  public function store($shareId, Request $request)
  {
    $share = $this->manageableShare($shareId);
    if (!$share instanceof Share) {
      return $share;
    }

    $validator = Validator::make($request->all(), [
      'name' => ['required', 'string', 'max:255'],
      'email' => ['required', 'email', 'max:255'],
      'send' => ['sometimes', 'boolean'],
    ]);
    if ($validator->fails()) {
      return $this->validationError($validator);
    }

    $recipient = $share->recipients()->create([
      'name' => $request->name,
      'email' => $request->email,
    ]);

    if ($request->boolean('send')) {
      $this->sendLink($share, $recipient);
    }

    return $this->recipientResponse($share, $recipient, 'Recipient added');
  }

  public function update($shareId, $recipientId, Request $request)
  {
    $share = $this->manageableShare($shareId);
    if (!$share instanceof Share) {
      return $share;
    }
    $recipient = $share->recipients()->where('id', $recipientId)->first();
    if (!$recipient) {
      return $this->notFound('Recipient not found');
    }

    $validator = Validator::make($request->all(), [
      'name' => ['sometimes', 'required', 'string', 'max:255'],
      'email' => ['sometimes', 'required', 'email', 'max:255'],
    ]);
    if ($validator->fails()) {
      return $this->validationError($validator);
    }

    $recipient->fill($request->only(['name', 'email']));
    $recipient->save();

    return $this->recipientResponse($share, $recipient, 'Recipient updated');
  }

  public function destroy($shareId, $recipientId)
  {
    $share = $this->manageableShare($shareId);
    if (!$share instanceof Share) {
      return $share;
    }
    $recipient = $share->recipients()->where('id', $recipientId)->first();
    if (!$recipient) {
      return $this->notFound('Recipient not found');
    }

    $recipient->delete();

    return response()->json([
      'status' => 'success',
      'message' => 'Recipient removed',
      'data' => [
        'recipients' => $share->recipients()->get()
      ]
    ]);
  }

  /**
   * Email a recipient the share's current link again.
   */
  public function resend($shareId, $recipientId)
  {
    $share = $this->manageableShare($shareId);
    if (!$share instanceof Share) {
      return $share;
    }
    $recipient = $share->recipients()->where('id', $recipientId)->first();
    if (!$recipient) {
      return $this->notFound('Recipient not found');
    }
    if ($share->status == 'deleted' || $share->expired) {
      return response()->json([
        'status' => 'error',
        'message' => 'Share is no longer available'
      ], 410);
    }

    $this->sendLink($share, $recipient);

    return $this->recipientResponse($share, $recipient, 'Link sent');
  }

  /**
   * People the signed-in user has sent shares to before, matching ?q= on name or email,
   * most recently used first. Only recipients still recorded on the user's own shares count.
   */
  public function suggestions(Request $request)
  {
    $user = Auth::user();
    if (!$user) {
      return response()->json([
        'status' => 'error',
        'message' => 'Unauthorized'
      ], 401);
    }

    $query = trim((string) $request->query('q', ''));
    if ($query === '') {
      return response()->json([
        'status' => 'success',
        'message' => 'Recipient suggestions',
        'data' => ['recipients' => []]
      ]);
    }

    // A % or _ typed by the user just widens the match, and only over their own contacts
    $like = '%' . $query . '%';
    $recipients = ShareRecipient::select('share_recipients.name', 'share_recipients.email')
      ->join('shares', 'shares.id', '=', 'share_recipients.share_id')
      ->where('shares.user_id', $user->id)
      ->where(function ($q) use ($like) {
        $q->where('share_recipients.name', 'like', $like)
          ->orWhere('share_recipients.email', 'like', $like);
      })
      ->orderByDesc('share_recipients.id')
      ->limit(100)
      ->get()
      ->unique(fn($recipient) => strtolower($recipient->email))
      ->take(8)
      ->values();

    return response()->json([
      'status' => 'success',
      'message' => 'Recipient suggestions',
      'data' => ['recipients' => $recipients]
    ]);
  }

  private function sendLink(Share $share, ShareRecipient $recipient): void
  {
    // shareCreatedMail names the sender from a User; reverse shares have no owner
    $sender = $share->user ?? Auth::user();

    sendEmail::dispatch($recipient->email, shareCreatedMail::class, [
      'user' => $sender,
      'share' => $share,
      'recipient' => ['name' => $recipient->name, 'email' => $recipient->email]
    ]);

    $recipient->notified_at = now();
    $recipient->save();
  }

  /**
   * The share if the signed-in user may manage it, otherwise an error response.
   */
  private function manageableShare($shareId)
  {
    $user = Auth::user();
    if (!$user) {
      return response()->json([
        'status' => 'error',
        'message' => 'Unauthorized'
      ], 401);
    }
    $share = Share::where('id', $shareId)->with(['user', 'invite'])->first();
    if (!$share) {
      return $this->notFound('Share not found');
    }
    if (!$share->canBeManagedBy($user)) {
      return response()->json([
        'status' => 'error',
        'message' => 'Unauthorized'
      ], 401);
    }
    return $share;
  }

  private function recipientResponse(Share $share, ShareRecipient $recipient, string $message)
  {
    return response()->json([
      'status' => 'success',
      'message' => $message,
      'data' => [
        'recipient' => $recipient->fresh(),
        'recipients' => $share->recipients()->get()
      ]
    ]);
  }

  private function notFound(string $message)
  {
    return response()->json([
      'status' => 'error',
      'message' => $message
    ], 404);
  }

  private function validationError($validator)
  {
    return response()->json([
      'status' => 'error',
      'message' => 'Validation failed',
      'data' => [
        'errors' => $validator->errors()
      ]
    ], 422);
  }
}
