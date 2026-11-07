<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Auth;
use App\Models\Share;
use Carbon\Carbon;
use App\Haikunator;
use App\Models\Setting;
use App\Models\User;
use App\Models\Download;
use App\Mail\shareDownloadedMail;
use App\Jobs\sendEmail;
use App\Services\SettingsService;
use App\Services\PatternGenerator;
use App\Jobs\cleanSpecificShares;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\RateLimiter;
use App\Mail\shareAccessChangedMail;

class SharesController extends Controller
{
  /**
   * Symfony's Content-Disposition filename cannot contain "/" or "\".
   * Also strip basic header-breaking characters.
   */
  private function sanitizeDownloadFilename(string $filename, string $fallback = 'download'): string
  {
    $sanitized = str_replace(['/', '\\'], '-', $filename);
    $sanitized = str_replace(["\r", "\n"], '', $sanitized);
    $sanitized = trim($sanitized);

    return $sanitized !== '' ? $sanitized : $fallback;
  }

  public function read($shareId)
  {
    $share = Share::where('long_id', $shareId)->with(['files', 'user'])->first();
    if ($error = $this->shareUnavailableResponse($share)) {
      return $error;
    }

    return response()->json([
      'status' => 'success',
      'message' => 'Share found',
      'data' => [

        'share' => $this->formatSharePublic($share, $this->isUnlocked($share))

      ]
    ]);
  }

  /**
   * Once a visitor enters a share's password they get a cookie that unlocks it until the
   * share expires. Its value is a signature over the share's id, link and password hash,
   * so a new password or a new link revokes every remembered unlock.
   */
  private function unlockCookieName(Share $share): string
  {
    return 'share_unlock_' . $share->id;
  }

  private function unlockToken(Share $share): string
  {
    return hash_hmac('sha256', $share->id . '|' . $share->long_id . '|' . $share->password, config('app.key'));
  }

  private function isUnlocked(Share $share): bool
  {
    if (!$share->password) {
      return true;
    }
    $cookie = request()->cookie($this->unlockCookieName($share));
    return is_string($cookie) && hash_equals($this->unlockToken($share), $cookie);
  }

  private function unlockCookie(Share $share)
  {
    $minutes = max(1, (int) ceil(Carbon::now()->diffInMinutes($share->expires_at, false)));
    return cookie(
      $this->unlockCookieName($share),
      $this->unlockToken($share),
      $minutes,
      '/',
      null,
      request()->isSecure(),
      true,
      false,
      'lax'
    );
  }

  /**
   * Check a password-protected share's password, the one place this is done.
   * A valid unlock cookie counts as the password. Wrong guesses are rate limited
   * per share and IP address.
   *
   * @return true|string true, or 'password_required', 'invalid_password' or 'too_many_attempts'
   */
  private function checkSharePassword(Share $share, $password)
  {
    if ($this->isUnlocked($share)) {
      return true;
    }

    $key = 'share-password:' . $share->id . '|' . request()->ip();
    if (RateLimiter::tooManyAttempts($key, 10)) {
      return 'too_many_attempts';
    }

    if (!$password) {
      return 'password_required';
    }

    if (!Hash::check($password, $share->password)) {
      RateLimiter::hit($key, 600);
      return 'invalid_password';
    }

    RateLimiter::clear($key);
    return true;
  }

  /**
   * The JSON error for a share that can't be viewed, or null if it can.
   */
  private function shareUnavailableResponse(?Share $share)
  {
    if (!$share) {
      return response()->json([
        'status' => 'error',
        'message' => 'Share not found'
      ], 404);
    }

    if ($share->expires_at < Carbon::now()) {
      return response()->json([
        'status' => 'error',
        'message' => 'Share expired'
      ], 410);
    }

    if ($share->download_limit != null && $share->download_count >= $share->download_limit) {
      return response()->json([
        'status' => 'error',
        'message' => 'Download limit reached'
      ], 410);
    }

    if (!$this->checkShareAccess($share)) {
      return response()->json([
        'status' => 'error',
        'message' => 'Share not found'
      ], 404);
    }

    return null;
  }

  /**
   * Check a share's password and, if it's right, return the share with its files
   * and remember the unlock in a cookie.
   */
  public function unlock($shareId, Request $request)
  {
    $share = Share::where('long_id', $shareId)->with(['files', 'user'])->first();
    if ($error = $this->shareUnavailableResponse($share)) {
      return $error;
    }

    $result = $this->checkSharePassword($share, $request->input('password'));
    if ($result !== true) {
      $messages = [
        'password_required' => 'Password required',
        'invalid_password' => 'Invalid password',
        'too_many_attempts' => 'Too many attempts',
      ];
      return response()->json([
        'status' => 'error',
        'message' => $messages[$result]
      ], $result === 'too_many_attempts' ? 429 : 401);
    }

    return response()->json([
      'status' => 'success',
      'message' => 'Share unlocked',
      'data' => [
        'share' => $this->formatSharePublic($share, true)
      ]
    ])->withCookie($this->unlockCookie($share));
  }

  private function formatSharePublic(Share $share, bool $unlocked = false)
  {
    // A locked password-protected share keeps the details listed in locked_hidden to itself
    $hidden = $share->password && !$unlocked
      ? array_values(array_intersect(Share::LOCKED_HIDEABLE, $share->locked_hidden ?? []))
      : [];
    $hides = fn(string $detail) => in_array($detail, $hidden, true);

    return [
      'id' => $share->id,
      'name' => $hides('name') ? null : $share->name,
      'description' => $hides('message') ? null : $share->description,
      'expires_at' => $share->expires_at,
      'download_limit' => $share->download_limit,
      'download_count' => $share->download_count,
      'size' => $hides('stats') ? null : $share->size,
      'file_count' => $hides('stats') ? null : $share->file_count,
      'files' => $hides('files') ? [] : $share->files->map(function ($file) {
        return [
          'id' => $file->id,
          'name' => $file->display_name, // Show original filename to users
          'size' => $file->size,
          'type' => $file->type,
          'full_path' => $file->full_path,
          'created_at' => $file->created_at,
          'updated_at' => $file->updated_at
        ];
      }),
      'user' => [
        'name' => $hides('sender') ? null : ($share->user ? $share->user->name : 'Guest User'),
      ],
      'password_protected' => $share->password ? true : false,
      'unlocked' => $share->password ? $unlocked : true,
      'hidden' => $hidden
    ];
  }

  private function formatSharePrivate(Share $share)
  {
    $share->password_protected = $share->password ? true : false;
    return $share;
  }

  /**
   * Check if a user can manage a share.
   * A user can manage a share if they are an admin, the owner, or if they created the invite for this share.
   */
  private function canManageShare(Share $share, $user): bool
  {
    return $share->canBeManagedBy($user);
  }

  private function checkShareAccess(Share $share)
  {
    if (!$share->public) {
      //get token from cookie
      $refreshToken = request()->cookie('refresh_token');

      if (!$refreshToken) {
        return false;
      }
      $user = Auth::setToken($refreshToken)->user();


      if (!$user) {
        return false;
      }
      $allowedUser = $share->invite->user;
      if ($user && $allowedUser && $allowedUser->id == $user->id) {
        return true;
      } else {
        return false;
      }
    }
    return true;
  }

  public function download($shareId)
  {

    $share = Share::where('long_id', $shareId)->with('files')->first();
    if (!$share) {
      return redirect()->to('/shares/' . $shareId);
    }

    $passwordCheck = $this->checkSharePassword($share, request()->input('password'));
    if ($passwordCheck !== true) {
      return redirect()->to('/shares/' . $shareId . '?error=' . $passwordCheck);
    }

    if ($share->expires_at < Carbon::now()) {
      return redirect()->to('/shares/' . $shareId);
    }

    if ($share->download_limit != null && $share->download_count >= $share->download_limit) {
      return redirect()->to('/shares/' . $shareId);
    }

    if (!$this->checkShareAccess($share)) {
      return redirect()->to('/shares/' . $shareId);
    }

    return $this->serveShareFiles($share);
  }

  /**
   * Send a share's files: the single file, or the zip once it's ready.
   * Downloads by recipients are recorded; an admin's download isn't.
   */
  private function serveShareFiles(Share $share, bool $record = true)
  {
    $sharePath = storage_path('app/shares/' . $share->path);

    //if there is only one file, download it directly
    if ($share->file_count == 1) {
      if (file_exists($sharePath . '/' . $share->files[0]->name)) {

        if ($record) {
          $this->createDownloadRecord($share);
        }

        return response()->download(
          $sharePath . '/' . $share->files[0]->name,
          $this->sanitizeDownloadFilename($share->files[0]->display_name)
        );
      } else {
        return redirect()->to('/shares/' . $share->long_id);
      }
    }

    //otherise let's check the status: pending, ready, or failed
    if ($share->status == 'pending') {
      return view('shares.pending', [
        'share' => $share,
        'settings' => $this->getSettings()
      ]);
    }

    //if the share is ready, download the zip file
    if ($share->status == 'ready') {
      $filename = $sharePath . '.zip';
      \Log::info('looking for: ' . $filename);
      //does the file exist?
      if (file_exists($filename)) {
        if ($record) {
          $this->createDownloadRecord($share);
        }

        return response()->download(
          $filename,
          $this->sanitizeDownloadFilename($share->name ?? '', 'share') . '.zip'
        );
      } else {
        //something went wrong, show the failed view
        return view('shares.failed', [
          'share' => $share,
          'settings' => $this->getSettings()
        ]);
      }
    }

    //if the share is failed, show the failed view
    if ($share->status == 'failed') {
      return view('shares.failed', [
        'share' => $share,
        'settings' => $this->getSettings()
      ]);
    }

    //if we got here we have no idea what to do so let's show the failed view
    return view('shares.failed', [
      'share' => $share,
      'settings' => $this->getSettings()
    ]);
  }

  /**
   * A short-lived link to download a share that still has its files, even once it has expired,
   * run out of downloads or is password protected. Admins can have one for any share and owners
   * for their own, each while the system allows it. These downloads aren't counted.
   */
  public function signedDownloadLink($shareId)
  {
    $user = Auth::user();
    $share = Share::find($shareId);
    if (!$share) {
      return response()->json(['status' => 'error', 'message' => 'Share not found'], 404);
    }
    $as = $this->expiredDownloadRole($share, $user);
    if (!$as) {
      return response()->json(['status' => 'error', 'message' => 'Downloading this share is turned off'], 403);
    }
    if ($share->status === 'deleted') {
      return response()->json(['status' => 'error', 'message' => 'Share deleted'], 410);
    }

    // relative, so the signature holds whatever scheme the proxies in front report
    $url = URL::temporarySignedRoute('shares.signedDownload', now()->addMinutes(5), ['id' => $share->id, 'as' => $as], false);

    return response()->json(['status' => 'success', 'data' => ['url' => $url]]);
  }

  public function signedDownload($shareId)
  {
    $share = Share::with('files')->find($shareId);
    // the setting is checked again, so turning it off also stops links already handed out
    $allowed = match (request()->query('as')) {
      'admin' => self::expiredDownloadsAllowed('admin'),
      'owner' => self::expiredDownloadsAllowed('owner'),
      default => false,
    };
    if (!$allowed || !$share || $share->status === 'deleted') {
      abort(404);
    }
    return $this->serveShareFiles($share, false);
  }

  /**
   * 'admin' or 'owner' if this user may download the share outside its limits, otherwise null.
   */
  private function expiredDownloadRole(Share $share, $user): ?string
  {
    if ($user->admin && self::expiredDownloadsAllowed('admin')) {
      return 'admin';
    }
    // canManageShare() would let any admin through, so check ownership itself
    $owns = $share->user_id == $user->id || ($share->invite && $share->invite->user_id == $user->id);
    if ($owns && self::expiredDownloadsAllowed('owner')) {
      return 'owner';
    }
    return null;
  }

  public static function expiredDownloadsAllowed(string $role): bool
  {
    return filter_var(Setting::where('key', $role . '_download_expired_enabled')->value('value') ?? true, FILTER_VALIDATE_BOOLEAN);
  }

  /**
   * Download a specific file from a multi-file share
   * The filepath can include nested directories (e.g., "folder/subfolder/file.txt")
   */
  public function downloadFile($shareId, $filepath)
  {
    $share = Share::where('long_id', $shareId)->with('files')->first();
    if (!$share) {
      return response()->json(['error' => 'Share not found'], 404);
    }

    $passwordCheck = $this->checkSharePassword($share, request()->input('password'));
    if ($passwordCheck === 'too_many_attempts') {
      return response()->json(['error' => 'Too many attempts'], 429);
    }
    if ($passwordCheck !== true) {
      return response()->json(['error' => $passwordCheck === 'password_required' ? 'Password required' : 'Invalid password'], 401);
    }

    if ($share->expires_at < Carbon::now()) {
      return response()->json(['error' => 'Share expired'], 410);
    }

    if ($share->download_limit != null && $share->download_count >= $share->download_limit) {
      return response()->json(['error' => 'Download limit reached'], 410);
    }

    if (!$this->checkShareAccess($share)) {
      return response()->json(['error' => 'Access denied'], 403);
    }

    // Decode the filepath (it may be URL encoded)
    $filepath = urldecode($filepath);

    // For single file shares, check if the requested file matches
    if ($share->file_count == 1) {
      $file = $share->files[0];
      $expectedPath = $file->full_path ? $file->full_path . '/' . $file->display_name : $file->display_name;
      
      if ($filepath === $expectedPath || $filepath === $file->display_name) {
        $sharePath = storage_path('app/shares/' . $share->path);
        $filePath = $sharePath . '/' . $file->name;
        
        if (file_exists($filePath)) {
          $this->createDownloadRecord($share);
          return response()->download(
            $filePath,
            $this->sanitizeDownloadFilename($file->display_name)
          );
        }
      }
      return response()->json(['error' => 'File not found'], 404);
    }

    // For multi-file shares, we need to extract from the zip
    if ($share->status !== 'ready') {
      return response()->json(['error' => 'Share is not ready'], 400);
    }

    $sharePath = storage_path('app/shares/' . $share->path);
    $zipPath = $sharePath . '.zip';

    if (!file_exists($zipPath)) {
      return response()->json(['error' => 'Archive not found'], 404);
    }

    // Find the file in the share's file list to validate it exists
    $foundFile = null;
    foreach ($share->files as $file) {
      $expectedPath = $file->full_path ? $file->full_path . '/' . $file->display_name : $file->display_name;
      if ($filepath === $expectedPath || $filepath === $file->display_name) {
        $foundFile = $file;
        break;
      }
    }

    if (!$foundFile) {
      return response()->json(['error' => 'File not found in share'], 404);
    }

    // Build the path as it exists in the zip (using sanitized name)
    $zipFilePath = $foundFile->full_path ? $foundFile->full_path . '/' . $foundFile->name : $foundFile->name;

    // Open the zip and stream the file
    $zip = new \ZipArchive();
    if ($zip->open($zipPath) !== true) {
      return response()->json(['error' => 'Failed to open archive'], 500);
    }

    // Try to find the file in the zip
    $fileIndex = $zip->locateName($zipFilePath);
    if ($fileIndex === false) {
      // Try without the path prefix (zip might have different structure)
      $fileIndex = $zip->locateName($foundFile->name);
    }

    if ($fileIndex === false) {
      $zip->close();
      return response()->json(['error' => 'File not found in archive'], 404);
    }

    // Get file stats
    $stat = $zip->statIndex($fileIndex);
    $fileSize = $stat['size'];

    // Get the stream
    $stream = $zip->getStream($zip->getNameIndex($fileIndex));
    if (!$stream) {
      $zip->close();
      return response()->json(['error' => 'Failed to read file from archive'], 500);
    }

    $this->createDownloadRecord($share);

    // Determine content type
    $mimeType = $foundFile->type ?? 'application/octet-stream';

    // Stream the response with larger buffer for better throughput
    // 64KB buffer size optimizes for tunnel/proxy scenarios
    return response()->stream(function () use ($stream, $zip) {
      while (!feof($stream)) {
        echo fread($stream, 65536);
        flush();
      }
      fclose($stream);
      $zip->close();
    }, 200, [
      'Content-Type' => $mimeType,
      'Content-Disposition' => 'attachment; filename="' . $this->sanitizeDownloadFilename($foundFile->display_name) . '"',
      'Content-Length' => $fileSize,
      'X-Accel-Buffering' => 'no', // Disable proxy buffering for streaming
    ]);
  }

  public function myShares(Request $request)
  {
    $user = Auth::user();

    if (!$user) {
      return response()->json([
        'status' => 'error',
        'message' => 'Unauthorized'
      ], 401);
    }

    $showDeleted = $request->input('show_deleted', false);

    // Include shares the user owns OR shares created for them via reverse share invites
    $shares = Share::where(function ($query) use ($user) {
      $query->where('user_id', $user->id)
        ->orWhereHas('invite', function ($q) use ($user) {
          $q->where('user_id', $user->id);
        });
    })->orderBy('created_at', 'desc')->with(['files', 'invite', 'recipients']);
    if ($showDeleted === 'false') {
      $shares = $shares->where('status', '!=', 'deleted');
    }
    $shares = $shares->get();
    return response()->json([
      'status' => 'success',
      'message' => 'My shares',
      'data' => [
        'shares' => $shares->map(function ($share) use ($user) {
          $formatted = $this->formatSharePrivate($share);
          // Flag shares that were created for the user (reverse shares) vs by the user
          $formatted->shared_with_me = $share->invite && $share->invite->user_id == $user->id && $share->user_id != $user->id;
          return $formatted;
        })
      ]
    ]);
  }

  public function allShares(Request $request)
  {
    $user = Auth::user();

    if (!$user || !$user->admin) {
      return response()->json([
        'status' => 'error',
        'message' => 'Unauthorized'
      ], 401);
    }

    $showDeleted = $request->input('show_deleted', false);
    $userId = $request->input('user_id', null);

    $shares = Share::orderBy('created_at', 'desc')->with(['files', 'user', 'invite.user', 'recipients']);
    
    if ($showDeleted === 'false') {
      $shares = $shares->where('status', '!=', 'deleted');
    }
    
    if ($userId) {
      $shares = $shares->where('user_id', $userId);
    }
    
    $shares = $shares->get();
    
    return response()->json([
      'status' => 'success',
      'message' => 'All shares',
      'data' => [
        'shares' => $shares->map(function ($share) {
          $formatted = $this->formatSharePrivate($share);
          // For reverse shares, show the invite creator's info; otherwise show the share owner
          if ($share->invite && $share->invite->user) {
            $formatted->user_name = $share->invite->user->name;
            $formatted->user_email = $share->invite->user->email;
          } else {
            $formatted->user_name = $share->user ? $share->user->name : 'Unknown User';
            $formatted->user_email = $share->user ? $share->user->email : '';
          }
          return $formatted;
        })
      ]
    ]);
  }

  public function expire($shareId)
  {
    $user = Auth::user();
    if (!$user) {
      return response()->json([
        'status' => 'error',
        'message' => 'Unauthorized'
      ], 401);
    }
    $share = Share::where('id', $shareId)->first();
    if (!$share) {
      return response()->json([
        'status' => 'error',
        'message' => 'Share not found'
      ], 404);
    }
    if (!$this->canManageShare($share, $user)) {
      return response()->json([
        'status' => 'error',
        'message' => 'Unauthorized'
      ], 401);
    }
    $share->expires_at = Carbon::now();
    $share->save();

    return response()->json([
      'status' => 'success',
      'message' => 'Share expired',
      'data' => [
        'share' => $share
      ]
    ]);
  }

  public function extend($shareId)
  {

    $user = Auth::user();
    if (!$user) {
      return response()->json([
        'status' => 'error',
        'message' => 'Unauthorized'
      ], 401);
    }
    $share = Share::where('id', $shareId)->first();
    if (!$share) {
      return response()->json([
        'status' => 'error',
        'message' => 'Share not found'
      ], 404);
    }
    if (!$this->canManageShare($share, $user)) {
      return response()->json([
        'status' => 'error',
        'message' => 'Unauthorized'
      ], 401);
    }
    $share->expires_at = Carbon::now()->addDays(7);
    $share->save();
    return response()->json([
      'status' => 'success',
      'message' => 'Share extended',
      'data' => [
        'share' => $share
      ]
    ]);
  }

  public function setDownloadLimit($shareId, Request $request)
  {
    $user = Auth::user();
    if (!$user) {
      return response()->json([
        'status' => 'error',
        'message' => 'Unauthorized'
      ], 401);
    }
    $share = Share::where('id', $shareId)->first();
    if (!$share) {
      return response()->json([
        'status' => 'error',
        'message' => 'Share not found'
      ], 404);
    }
    if (!$this->canManageShare($share, $user)) {
      return response()->json([
        'status' => 'error',
        'message' => 'Unauthorized'
      ], 401);
    }
    if ($request->amount == -1) {
      $share->download_limit = null;
    } else {
      $share->download_limit = $request->amount;
    }
    $share->save();
    return response()->json([
      'status' => 'success',
      'message' => 'Download limit set',
      'data' => [
        'share' => $share
      ]
    ]);
  }

  /**
   * Edit a share after it has been created: its name, what it hides while locked, or
   * remove its password. Setting or changing a password goes through changeAccess().
   */
  public function update($shareId, Request $request)
  {
    $user = Auth::user();
    if (!$user) {
      return response()->json([
        'status' => 'error',
        'message' => 'Unauthorized'
      ], 401);
    }
    $share = Share::where('id', $shareId)->first();
    if (!$share) {
      return response()->json([
        'status' => 'error',
        'message' => 'Share not found'
      ], 404);
    }
    if (!$this->canManageShare($share, $user)) {
      return response()->json([
        'status' => 'error',
        'message' => 'Unauthorized'
      ], 401);
    }

    $validator = Validator::make($request->all(), [
      'name' => ['sometimes', 'required', 'string', 'max:255'],
      'remove_password' => ['sometimes', 'boolean'],
      'locked_hidden' => ['sometimes', 'array'],
      'locked_hidden.*' => ['string', Rule::in(Share::LOCKED_HIDEABLE)],
    ]);

    if ($validator->fails()) {
      return response()->json([
        'status' => 'error',
        'message' => 'Validation failed',
        'data' => [
          'errors' => $validator->errors()
        ]
      ], 422);
    }

    if ($request->has('name')) {
      $share->name = $request->name;
    }

    if ($request->has('locked_hidden')) {
      $share->locked_hidden = array_values(array_unique($request->input('locked_hidden', [])));
    }

    if ($request->boolean('remove_password')) {
      $share->password = null;
    }

    $share->save();

    return response()->json([
      'status' => 'success',
      'message' => 'Share updated',
      'data' => [
        'share' => $this->formatSharePrivate($share->load(['files', 'recipients']))
      ]
    ]);
  }

  /**
   * Change how a share is reached: give it a new link, a new password, or both,
   * then optionally email chosen recipients about it.
   *
   * new_link: bool, password + password_confirmation: optional,
   * notify: [recipient ids], include_password: bool (put the new password in the email)
   */
  public function changeAccess($shareId, Request $request)
  {
    $user = Auth::user();
    if (!$user) {
      return response()->json([
        'status' => 'error',
        'message' => 'Unauthorized'
      ], 401);
    }
    $share = Share::where('id', $shareId)->with(['recipients', 'user'])->first();
    if (!$share) {
      return response()->json([
        'status' => 'error',
        'message' => 'Share not found'
      ], 404);
    }
    if (!$this->canManageShare($share, $user)) {
      return response()->json([
        'status' => 'error',
        'message' => 'Unauthorized'
      ], 401);
    }
    if ($share->status == 'deleted') {
      return response()->json([
        'status' => 'error',
        'message' => 'Share has been deleted'
      ], 410);
    }

    $validator = Validator::make($request->all(), [
      'new_link' => ['sometimes', 'boolean'],
      'password' => ['nullable', 'string', 'confirmed'],
      'notify' => ['sometimes', 'array'],
      'notify.*' => ['integer'],
      'include_password' => ['sometimes', 'boolean'],
    ]);
    if ($validator->fails()) {
      return response()->json([
        'status' => 'error',
        'message' => 'Validation failed',
        'data' => [
          'errors' => $validator->errors()
        ]
      ], 422);
    }

    $newLink = $request->boolean('new_link');
    $newPassword = $request->filled('password') ? $request->input('password') : null;
    if (!$newLink && $newPassword === null) {
      return response()->json([
        'status' => 'error',
        'message' => 'Nothing to change'
      ], 422);
    }

    if ($newLink) {
      // The zip job writes to a path built from the current long_id
      if ($share->status == 'pending') {
        return response()->json([
          'status' => 'error',
          'message' => 'Share is still being prepared'
        ], 409);
      }
      if (!$this->moveToNewLink($share)) {
        return response()->json([
          'status' => 'error',
          'message' => 'Failed to move share files'
        ], 500);
      }
    }

    if ($newPassword !== null) {
      $share->password = Hash::make($newPassword);
    }
    $share->save();

    $notifyIds = collect($request->input('notify', []))->map(fn($id) => (int) $id);
    // Admins can stop new passwords ever being put in an email
    $passwordEmailsAllowed = filter_var(Setting::where('key', 'password_in_email_allowed')->value('value') ?? true, FILTER_VALIDATE_BOOLEAN);
    $includePassword = $passwordEmailsAllowed && $newPassword !== null && $request->boolean('include_password');
    $senderName = $share->user ? $share->user->name : $user->name;
    $notified = [];
    foreach ($share->recipients as $recipient) {
      if ($notifyIds->contains($recipient->id)) {
        sendEmail::dispatch($recipient->email, shareAccessChangedMail::class, [
          'share' => $share,
          'recipient' => ['name' => $recipient->name, 'email' => $recipient->email],
          'senderName' => $senderName,
          'linkChanged' => $newLink,
          'passwordChanged' => $newPassword !== null,
          'newPassword' => $includePassword ? $newPassword : null
        ]);
        $recipient->notified_at = now();
        $recipient->save();
        $notified[] = $recipient->id;
      }
    }

    return response()->json([
      'status' => 'success',
      'message' => 'Share access changed',
      'data' => [
        'share' => $this->formatSharePrivate($share->load(['files', 'recipients'])),
        'notified' => $notified
      ]
    ]);
  }

  /**
   * Give a share a new long_id. Its files live at shares/<user folder>/<long_id>
   * (a folder) and/or <long_id>.zip, so those move too; the old URL stops working.
   */
  private function moveToNewLink(Share $share): bool
  {
    $newLongId = $this->generateLongId();
    $oldPath = $share->path;
    $newPath = dirname($oldPath) . '/' . $newLongId;
    $oldBase = storage_path('app/shares/' . $oldPath);
    $newBase = storage_path('app/shares/' . $newPath);

    $moved = [];
    foreach (['', '.zip'] as $suffix) {
      if (file_exists($oldBase . $suffix)) {
        if (!rename($oldBase . $suffix, $newBase . $suffix)) {
          // put back anything already moved
          foreach ($moved as $done) {
            rename($newBase . $done, $oldBase . $done);
          }
          \Log::error('Failed to move share files for new link', ['share_id' => $share->id]);
          return false;
        }
        $moved[] = $suffix;
      }
    }

    $share->long_id = $newLongId;
    $share->path = $newPath;
    return true;
  }

  /**
   * Delete a share's files now rather than waiting for the cleanup after it expires.
   * Admins can delete any share; owners their own, unless the system turns that off.
   */
  public function destroy($shareId)
  {
    $user = Auth::user();
    $share = Share::find($shareId);
    if (!$share) {
      return response()->json(['status' => 'error', 'message' => 'Share not found'], 404);
    }
    if (!$share->canBeDeletedBy($user)) {
      return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
    }
    if ($share->status === 'deleted') {
      return response()->json(['status' => 'error', 'message' => 'Share already deleted'], 409);
    }

    if (!$share->expires_at || $share->expires_at->isFuture()) {
      $share->expires_at = Carbon::now();
      $share->save();
    }
    if (!$share->cleanFiles(true)) {
      return response()->json(['status' => 'error', 'message' => 'Failed to delete share'], 500);
    }

    return response()->json([
      'status' => 'success',
      'message' => 'Share deleted',
      'data' => ['share' => $share->fresh()]
    ]);
  }

  public function pruneExpiredShares()
  {
    $user = Auth::user();
    if (!$user) {
      return response()->json([
        'status' => 'error',
        'message' => 'Unauthorized'
      ], 401);
    }

    // Include shares the user owns OR shares created for them via reverse share invites
    $shares = Share::where(function ($query) use ($user) {
      $query->where('user_id', $user->id)
        ->orWhereHas('invite', function ($q) use ($user) {
          $q->where('user_id', $user->id);
        });
    })->where('expires_at', '<', Carbon::now())->get();
    cleanSpecificShares::dispatch($shares->pluck('id')->toArray(), $user->id);

    return response()->json([
      'status' => 'success',
      'message' => 'Expired shares scheduled for deletion',
      'data' => [
        'shares' => $shares
      ]
    ]);
  }


  public function generateLongId()
  {
    $settingsService = new SettingsService();
    $mode = $settingsService->get('share_url_mode') ?? 'haiku';

    $maxAttempts = 10;
    $attempts = 0;

    $id = $this->generateIdByMode($mode, $settingsService);
    while (Share::where('long_id', $id)->exists() && $attempts < $maxAttempts) {
      $id = $this->generateIdByMode($mode, $settingsService);
      $attempts++;
    }

    if ($attempts >= $maxAttempts) {
      throw new \Exception('Unable to generate unique long_id after ' . $maxAttempts . ' attempts');
    }

    return $id;
  }

  private function generateIdByMode(string $mode, SettingsService $settingsService): string
  {
    return match ($mode) {
      'pattern' => $this->generatePatternId($settingsService),
      default => $this->generateHaikuId(),
    };
  }

  private function generateHaikuId(): string
  {
    return Haikunator::haikunate() . '-' . Haikunator::haikunate();
  }

  private function generatePatternId(SettingsService $settingsService): string
  {
    $pattern = $settingsService->get('share_url_pattern') ?? '******';
    $generator = new PatternGenerator();
    
    $error = $generator->validate($pattern);
    if ($error !== null) {
      // Fallback to a safe default pattern if the configured one is invalid
      \Log::warning("Invalid share URL pattern configured: {$error}. Using default.");
      $pattern = '******';
    }
    
    return $generator->generate($pattern);
  }

  private function getSettings()
  {
    $settings = Setting::whereLike('group', 'ui%')->get();
    $indexedSettings = [];
    foreach ($settings as $setting) {
      $indexedSettings[$setting->key] = $setting->value;
    }

    //have we any users in the database?
    $userCount = User::count();
    $indexedSettings['setup_needed'] = $userCount > 0 ? 'false' : 'true';

    //grab the app url from env
    $appURL = env('APP_URL');
    $indexedSettings['api_url'] = $appURL;

    return $indexedSettings;
  }

  private function createDownloadRecord(Share $share)
  {
    $ipAddress = request()->ip();
    $userAgent = request()->userAgent();
    $download = Download::create([
      'share_id' => $share->id,
      'ip_address' => $ipAddress,
      'user_agent' => $userAgent
    ]);
    $download->save();

    if ($share->download_count == 0) {
      $this->sendShareDownloadedEmail($share);
    }

    $share->download_count++;
    $share->save();
    return $download;
  }

  private function sendShareDownloadedEmail(Share $share)
  {
    $settingsService = new SettingsService();
    $sendEmail = $settingsService->get('emails_share_downloaded_enabled');
    if ($sendEmail == 'true' && $share->user) {
      sendEmail::dispatch($share->user->email, shareDownloadedMail::class, ['share' => $share]);
    }
  }
}
