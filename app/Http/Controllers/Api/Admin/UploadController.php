<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\OperationLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class UploadController extends Controller
{
    /**
     * Storable extensions, keyed by the extension guessed from the sniffed MIME type.
     * Anything outside this map never reaches disk.
     */
    private const EXTENSIONS = [
        'jpg' => 'jpg',
        'jpeg' => 'jpg',
        'png' => 'png',
        'gif' => 'gif',
        'webp' => 'webp',
        // Site favicons are usually .ico. SVG is deliberately absent: it can carry
        // script, and it would be served from this origin.
        'ico' => 'ico',
    ];

    public function store(Request $request)
    {
        // Validated by hand rather than via $request->validate() so the failure body is
        // the flat {"message": ...} the editor and the uploader component both expect,
        // instead of Laravel's {"message", "errors"} envelope.
        $validator = Validator::make($request->all(), [
            // `mimetypes` reads the real type out of the file's bytes with finfo, which is a
            // stronger check than the `image` rule's extension-and-header guess, and it keeps
            // SVG out. `mimes` is kept alongside it so a mismatched extension is also rejected.
            'file' => [
                'required',
                'file',
                'max:2048',
                'mimes:jpg,jpeg,png,gif,webp,ico',
                'mimetypes:image/jpeg,image/png,image/gif,image/webp,image/x-icon,image/vnd.microsoft.icon',
            ],
        ], [
            'file.required' => '请选择要上传的图片。',
            'file.file' => '上传的内容不是有效的文件。',
            'file.mimes' => '仅支持 jpg、png、gif、webp、ico 格式的图片。',
            'file.mimetypes' => '文件内容不是有效的图片。',
            'file.max' => '图片大小不能超过 2MB。',
            'file.uploaded' => '图片上传失败，可能超出了服务器允许的大小。',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first('file')], 422);
        }

        $file = $request->file('file');

        // The client-supplied name is never used, not even for the extension: it is the
        // one part of the upload an attacker fully controls. extension() derives it from
        // the sniffed MIME type, which the `mimes` rule above has already constrained.
        // Falling back to the client extension is safe here and only here: `mimes` has
        // already constrained it to the allowlist and `mimetypes` has confirmed the bytes
        // agree, so it cannot be used to smuggle an extension past the map. The fallback
        // exists because guessExtension() has no entry for some icon MIME spellings.
        $extension = self::EXTENSIONS[strtolower((string) $file->extension())]
            ?? self::EXTENSIONS[strtolower((string) $file->getClientOriginalExtension())]
            ?? null;

        if ($extension === null) {
            return response()->json(['message' => '无法识别图片格式，请换一张图片重试。'], 422);
        }

        $directory = 'uploads/' . date('Y') . '/' . date('m');
        $name = Str::random(32) . '.' . $extension;

        $path = $file->storeAs($directory, $name, 'public');

        if ($path === false) {
            return response()->json(['message' => '图片保存失败，请稍后重试。'], 422);
        }

        OperationLog::log('上传图片', 'upload', null, $path);

        return response()->json([
            'url' => '/storage/' . $path,
            'path' => $path,
        ]);
    }
}
