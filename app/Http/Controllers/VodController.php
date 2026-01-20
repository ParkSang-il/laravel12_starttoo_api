<?php

namespace App\Http\Controllers;

use App\Models\Portfolio;
use App\Models\PortfolioVideo;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class VodController extends Controller
{
    /**
     * VOD 인코딩 완료 콜백 처리
     * 실제 콜백 구조: fileId, filePath, link, status 등
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function callback(Request $request): JsonResponse
    {
        try {
            // 모든 요청 데이터 상세 로깅 (daily 채널)
            Log::channel('daily')->info('=== VOD 콜백 수신 ===', [
                'timestamp' => now()->toDateTimeString(),
                'request_method' => $request->method(),
                'request_url' => $request->fullUrl(),
                'request_headers' => $request->headers->all(),
                'request_body_raw' => $request->getContent(),
                'request_all' => $request->all(),
                'request_json' => $request->json() ? $request->json()->all() : null,
                'input_data' => [
                    'fileId' => $request->input('fileId'),
                    'filePath' => $request->input('filePath'),
                    'link' => $request->input('link'),
                    'status' => $request->input('status'),
                    'categoryId' => $request->input('categoryId'),
                    'categoryName' => $request->input('categoryName'),
                    'encodingOptionId' => $request->input('encodingOptionId'),
                    'outputType' => $request->input('outputType'),
                ],
                'server_data' => $request->server->all(),
            ]);

            // 일반 로그에도 간단히 기록
            Log::info('VOD 콜백 수신', [
                'fileId' => $request->input('fileId'),
                'filePath' => $request->input('filePath'),
                'status' => $request->input('status'),
            ]);

            // 실제 콜백 구조에 맞춘 유효성 검사
            $validator = Validator::make($request->all(), [
                'fileId' => 'required|integer',
                'filePath' => 'required|string',
                'link' => 'required|string',
                'status' => 'required|string|in:COMPLETE,FAILED,PROCESSING',
                'categoryId' => 'nullable|integer',
                'categoryName' => 'nullable|string',
                'encodingOptionId' => 'nullable|integer',
                'outputType' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                Log::warning('VOD 콜백 유효성 검사 실패', [
                    'errors' => $validator->errors(),
                    'request_data' => $request->all(),
                ]);

                return response()->json([
                    'result' => 'error',
                    'message' => '유효성 검사 실패',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $fileId = $request->input('fileId');
            $filePath = $request->input('filePath');
            $status = $request->input('status');
            $mp4Link = $request->input('link'); // MP4 파일 링크

            // filePath로 포트폴리오 비디오 찾기
            // filePath 예시: "/startoo-vod-category/20260120004052_23456789_AVC_HD_1Pass_30fps.mp4"
            Log::info('VOD 콜백: 포트폴리오 비디오 검색 시작', [
                'file_id' => $fileId,
                'file_path' => $filePath,
            ]);

            // DB에 저장된 모든 video_file_path 로그 출력 (디버깅용)
            $allVideos = PortfolioVideo::select('id', 'portfolio_id', 'video_file_path', 'video_status')
                ->where('video_status', 'pending')
                ->orWhere('video_status', 'processing')
                ->get();
            
            Log::info('VOD 콜백: 대기 중인 포트폴리오 비디오 목록', [
                'pending_videos_count' => $allVideos->count(),
                'pending_videos' => $allVideos->map(function ($video) {
                    return [
                        'id' => $video->id,
                        'portfolio_id' => $video->portfolio_id,
                        'video_file_path' => $video->video_file_path,
                        'video_status' => $video->video_status,
                    ];
                })->toArray(),
            ]);

            // 전체 경로 매칭 시도
            $portfolioVideo = PortfolioVideo::where('video_file_path', $filePath)->first();
            
            // 전체 경로로 못 찾으면 파일명만으로 매칭 시도
            if (!$portfolioVideo && $filePath) {
                $fileName = basename($filePath); // "20260120004052_23456789_AVC_HD_1Pass_30fps.mp4" 추출
                Log::info('VOD 콜백: 전체 경로 매칭 실패, 파일명으로 재검색', [
                    'file_name' => $fileName,
                ]);
                $portfolioVideo = PortfolioVideo::where('video_file_path', 'like', '%' . $fileName)->first();
            }

            // 파일명에서 인코딩 옵션 제거하여 원본 파일명 추출
            // 예: "20260120024700_uvwxyzAB_AVC_HD_1Pass_30fps.mp4" -> "20260120024700_uvwxyzAB.mp4"
            if (!$portfolioVideo && $filePath) {
                $fileName = basename($filePath);
                // 파일명에서 확장자 제거
                $fileNameWithoutExt = pathinfo($fileName, PATHINFO_FILENAME);
                
                // "_AVC_HD_1Pass_30fps" 인코딩 옵션 제거
                // 패턴: 타임스탬프_식별자_AVC_HD_1Pass_30fps
                // 예: "20260120024700_uvwxyzAB_AVC_HD_1Pass_30fps" -> "20260120024700_uvwxyzAB"
                $originalFileName = preg_replace('/_AVC_HD_1Pass_30fps$/', '', $fileNameWithoutExt);
                
                if ($originalFileName !== $fileNameWithoutExt) {
                    // 원본 파일명으로 매칭 시도 (확장자 포함)
                    $originalFileNameWithExt = $originalFileName . '.mp4';
                    
                    Log::info('VOD 콜백: 인코딩 옵션 제거하여 원본 파일명 추출', [
                        'original_file_name' => $fileNameWithoutExt,
                        'extracted_original' => $originalFileName,
                        'original_with_ext' => $originalFileNameWithExt,
                    ]);
                    
                    // 원본 파일명으로 매칭 시도 (대소문자 무시)
                    $portfolioVideo = PortfolioVideo::where(function ($query) use ($originalFileName, $originalFileNameWithExt) {
                        $query->whereRaw('LOWER(video_file_path) LIKE ?', ['%' . strtolower($originalFileName) . '%'])
                              ->orWhereRaw('LOWER(video_file_path) LIKE ?', ['%' . strtolower($originalFileNameWithExt) . '%']);
                    })->first();
                }
            }


            if (!$portfolioVideo) {
                Log::channel('daily')->warning('VOD 콜백: 포트폴리오 비디오를 찾을 수 없음', [
                    'file_id' => $fileId,
                    'file_path' => $filePath,
                    'file_name' => $filePath ? basename($filePath) : null,
                    'searched_path' => $filePath,
                    'searched_file_name' => $filePath ? basename($filePath) : null,
                    'all_pending_videos' => $allVideos->map(function ($video) {
                        return [
                            'id' => $video->id,
                            'video_file_path' => $video->video_file_path,
                        ];
                    })->toArray(),
                ]);

                Log::warning('VOD 콜백: 포트폴리오 비디오를 찾을 수 없음', [
                    'file_id' => $fileId,
                    'file_path' => $filePath,
                    'file_name' => $filePath ? basename($filePath) : null,
                    'pending_videos_count' => $allVideos->count(),
                ]);

                return response()->json([
                    'result' => 'error',
                    'message' => '포트폴리오 비디오를 찾을 수 없습니다.',
                ], 404);
            }

            Log::info('VOD 콜백: 포트폴리오 비디오 찾음', [
                'portfolio_video_id' => $portfolioVideo->id,
                'portfolio_id' => $portfolioVideo->portfolio_id,
                'file_id' => $fileId,
            ]);

            $portfolio = $portfolioVideo->portfolio;

            // 상태에 따라 처리
            Log::info('VOD 콜백: 상태별 처리 시작', [
                'status' => $status,
                'portfolio_id' => $portfolio->id,
                'portfolio_video_id' => $portfolioVideo->id,
            ]);

            if ($status === 'COMPLETE') {
                // HLS URL 생성
                $hlsUrl = $this->generateHlsUrl($filePath, $mp4Link);
                
                Log::info('VOD 콜백: HLS URL 생성 완료', [
                    'hls_url' => $hlsUrl,
                    'file_path' => $filePath,
                    'mp4_link' => $mp4Link,
                ]);
                
                // 썸네일 URL 생성 (필요시)
                $thumbnailUrl = $this->generateThumbnailUrl($filePath);

                // 포트폴리오 비디오 업데이트
                $updateData = [
                    'video_url' => $hlsUrl,
                    'video_thumbnail_url' => $thumbnailUrl,
                    'video_job_id' => $fileId, // fileId를 job_id로 저장
                    'video_status' => 'complete',
                ];

                Log::info('VOD 콜백: 포트폴리오 비디오 업데이트 데이터', [
                    'update_data' => $updateData,
                ]);

                $portfolioVideo->update($updateData);

                Log::channel('daily')->info('VOD 인코딩 완료: 포트폴리오 비디오 업데이트 성공', [
                    'portfolio_id' => $portfolio->id,
                    'portfolio_video_id' => $portfolioVideo->id,
                    'file_id' => $fileId,
                    'file_path' => $filePath,
                    'mp4_link' => $mp4Link,
                    'hls_url' => $hlsUrl,
                    'thumbnail_url' => $thumbnailUrl,
                    'updated_at' => now()->toDateTimeString(),
                ]);

                Log::info('VOD 인코딩 완료: 포트폴리오 비디오 업데이트 성공', [
                    'portfolio_id' => $portfolio->id,
                    'portfolio_video_id' => $portfolioVideo->id,
                    'file_id' => $fileId,
                    'hls_url' => $hlsUrl,
                ]);

                return response()->json([
                    'result' => 'ok',
                    'message' => '재생 URL이 저장되었습니다.',
                ], 200);
            } elseif ($status === 'FAILED') {
                // 인코딩 실패 처리
                $portfolioVideo->update([
                    'video_job_id' => $fileId,
                    'video_status' => 'failed',
                ]);

                Log::channel('daily')->error('VOD 인코딩 실패', [
                    'portfolio_id' => $portfolio->id,
                    'portfolio_video_id' => $portfolioVideo->id,
                    'file_id' => $fileId,
                    'file_path' => $filePath,
                    'status' => $status,
                    'updated_at' => now()->toDateTimeString(),
                ]);

                Log::error('VOD 인코딩 실패', [
                    'portfolio_id' => $portfolio->id,
                    'portfolio_video_id' => $portfolioVideo->id,
                    'file_id' => $fileId,
                    'file_path' => $filePath,
                ]);

                return response()->json([
                    'result' => 'ok',
                    'message' => '인코딩 실패 상태가 저장되었습니다.',
                ], 200);
            } elseif ($status === 'PROCESSING') {
                // 인코딩 진행 중 처리
                $portfolioVideo->update([
                    'video_job_id' => $fileId,
                    'video_status' => 'processing',
                ]);

                Log::channel('daily')->info('VOD 인코딩 진행 중', [
                    'portfolio_id' => $portfolio->id,
                    'portfolio_video_id' => $portfolioVideo->id,
                    'file_id' => $fileId,
                    'file_path' => $filePath,
                    'status' => $status,
                    'updated_at' => now()->toDateTimeString(),
                ]);

                Log::info('VOD 인코딩 진행 중', [
                    'portfolio_id' => $portfolio->id,
                    'portfolio_video_id' => $portfolioVideo->id,
                    'file_id' => $fileId,
                    'file_path' => $filePath,
                ]);

                return response()->json([
                    'result' => 'ok',
                    'message' => '인코딩 진행 중 상태가 저장되었습니다.',
                ], 200);
            }

            return response()->json([
                'result' => 'ok',
            ], 200);
        } catch (\Exception $e) {
            Log::channel('daily')->error('VOD 콜백 처리 중 오류 발생', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all(),
                'request_body_raw' => $request->getContent(),
                'timestamp' => now()->toDateTimeString(),
            ]);

            Log::error('VOD 콜백 처리 중 오류 발생', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all(),
            ]);

            return response()->json([
                'result' => 'error',
                'message' => '콜백 처리 중 오류가 발생했습니다.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * HLS URL 생성
     * NCP VOD Station CDN 구조에 맞춰 생성
     * 실제 형식: https://{CDN_DOMAIN}/hls/{ENCRYPTED_BUCKET_NAME}/{filePath}/index.m3u8
     * 
     * @param string $filePath 파일 경로 (예: "/startoo-vod-category/20260120004052_23456789_AVC_HD_1Pass_30fps.mp4")
     * @param string $mp4Link MP4 파일 링크
     * @return string HLS URL
     */
    private function generateHlsUrl(string $filePath, string $mp4Link): string
    {
        // CDN 도메인 (config에서 가져오기)
        $cdnDomain = config('services.vod.cdn_domain', env('VOD_CDN_DOMAIN', 'cdn.starttoo.com'));
        
        // 암호화된 버킷명 (config에서 가져오기)
        $encryptedBucketName = config('services.vod.encrypted_bucket_name', env('VOD_ENCRYPTED_BUCKET_NAME', ''));
        
        // filePath에서 경로 추출
        // "/startoo-vod-category/20260120004052_23456789_AVC_HD_1Pass_30fps.mp4"
        // -> "startoo-vod-category/20260120004052_23456789_AVC_HD_1Pass_30fps.mp4"
        $path = ltrim($filePath, '/');
        
        // HLS URL 생성
        // https://yypo7c7k13595.edge.naverncp.com/hls/0xDUoBdQW56mtDqtRYUVti9qOGtVRUx--SUwnfA1x~8_/startoo-vod-category/20260120004052_23456789_AVC_HD_1Pass_30fps.mp4/index.m3u8
        return "https://{$cdnDomain}/hls/{$encryptedBucketName}/{$path}/index.m3u8";
    }

    /**
     * 썸네일 URL 생성
     * 
     * @param string $filePath 파일 경로
     * @return string|null 썸네일 URL
     */
    private function generateThumbnailUrl(string $filePath): ?string
    {
        // 썸네일 생성 로직 (필요시)
        // 예: Object Storage의 썸네일 경로 또는 별도 생성
        // 현재는 null 반환, 필요시 구현
        return null;
    }

    /**
     * VOD 콜백 테스트용 (모든 데이터 로깅)
     * 실제 콜백 데이터 구조를 확인하기 위한 테스트 엔드포인트
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function testCallback(Request $request): JsonResponse
    {
        try {
            // 모든 요청 데이터 상세 로깅
            Log::channel('daily')->info('=== VOD 테스트 콜백 수신 ===', [
                'timestamp' => now()->toDateTimeString(),
                'request_method' => $request->method(),
                'request_url' => $request->fullUrl(),
                'request_headers' => $request->headers->all(),
                'request_body_raw' => $request->getContent(),
                'request_all' => $request->all(),
                'request_json' => $request->json()->all(),
                'input_data' => [
                    'fileId' => $request->input('fileId'),
                    'filePath' => $request->input('filePath'),
                    'link' => $request->input('link'),
                    'status' => $request->input('status'),
                    'categoryId' => $request->input('categoryId'),
                    'categoryName' => $request->input('categoryName'),
                    'encodingOptionId' => $request->input('encodingOptionId'),
                    'outputType' => $request->input('outputType'),
                ],
                'server_data' => $request->server->all(),
            ]);

            // 콘솔에도 출력 (개발 환경에서 확인용)
            if (config('app.debug')) {
                \Log::info('VOD 테스트 콜백 - 콘솔 출력', [
                    'fileId' => $request->input('fileId'),
                    'status' => $request->input('status'),
                    'filePath' => $request->input('filePath'),
                    'link' => $request->input('link'),
                ]);
            }

            return response()->json([
                'result' => 'ok',
                'message' => '테스트 콜백이 로그에 저장되었습니다.',
                'logged_data' => [
                    'fileId' => $request->input('fileId'),
                    'status' => $request->input('status'),
                    'filePath' => $request->input('filePath'),
                    'link' => $request->input('link'),
                    'timestamp' => now()->toDateTimeString(),
                ],
            ], 200);
        } catch (\Exception $e) {
            Log::error('VOD 테스트 콜백 처리 중 오류 발생', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all(),
            ]);

            return response()->json([
                'result' => 'error',
                'message' => '테스트 콜백 처리 중 오류가 발생했습니다.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
}

