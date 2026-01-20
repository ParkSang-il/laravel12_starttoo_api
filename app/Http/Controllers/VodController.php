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
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function callback(Request $request): JsonResponse
    {
        try {
            // 모든 요청 데이터 로깅 (디버깅용)
            Log::info('VOD 콜백 수신', [
                'request_data' => $request->all(),
                'headers' => $request->headers->all(),
            ]);

            // 유효성 검사
            $validator = Validator::make($request->all(), [
                'jobId' => 'required|integer',
                'status' => 'required|string|in:COMPLETE,FAILED,PROCESSING',
                'categoryId' => 'nullable|integer',
                'categoryName' => 'nullable|string',
                'input' => 'required|array',
                'input.bucketName' => 'nullable|string',
                'input.filePath' => 'required|string', // 필수로 변경
                'output' => 'nullable|array',
                'output.*.type' => 'nullable|string',
                'output.*.url' => 'nullable|string',
                'output.*.bitrate' => 'nullable|string',
                'output.*.resolution' => 'nullable|string',
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

            $jobId = $request->input('jobId');
            $status = $request->input('status');
            $filePath = $request->input('input.filePath'); // 파일 경로 추출
            $outputs = $request->input('output', []);

            // 파일 경로로 포트폴리오 비디오 찾기
            // 전체 경로 매칭 시도
            $portfolioVideo = PortfolioVideo::where('video_file_path', $filePath)->first();
            
            // 전체 경로로 못 찾으면 파일명만으로 매칭 시도
            if (!$portfolioVideo && $filePath) {
                $fileName = basename($filePath); // "test2.mp4" 추출
                $portfolioVideo = PortfolioVideo::where('video_file_path', 'like', '%' . $fileName)->first();
            }

            if (!$portfolioVideo) {
                Log::warning('VOD 콜백: 포트폴리오 비디오를 찾을 수 없음', [
                    'job_id' => $jobId,
                    'file_path' => $filePath,
                    'searched_file_name' => $filePath ? basename($filePath) : null,
                ]);

                return response()->json([
                    'result' => 'error',
                    'message' => '포트폴리오 비디오를 찾을 수 없습니다.',
                ], 404);
            }

            $portfolio = $portfolioVideo->portfolio;

            // 상태에 따라 처리
            if ($status === 'COMPLETE') {
                // HLS URL 추출
                $hlsUrl = null;
                $thumbnailUrl = null;

                foreach ($outputs as $output) {
                    if (isset($output['type']) && $output['type'] === 'HLS' && isset($output['url'])) {
                        $hlsUrl = $output['url'];
                    }
                    if (isset($output['type']) && $output['type'] === 'THUMBNAIL' && isset($output['url'])) {
                        $thumbnailUrl = $output['url'];
                    }
                }

                if ($hlsUrl) {
                    // 포트폴리오 비디오 업데이트 (jobId도 함께 저장)
                    $portfolioVideo->update([
                        'video_url' => $hlsUrl,
                        'video_thumbnail_url' => $thumbnailUrl,
                        'video_job_id' => $jobId, // 콜백에서 받은 jobId 저장
                        'video_status' => 'complete',
                    ]);

                    Log::info('VOD 인코딩 완료: 포트폴리오 비디오 업데이트 성공', [
                        'portfolio_id' => $portfolio->id,
                        'portfolio_video_id' => $portfolioVideo->id,
                        'job_id' => $jobId,
                        'file_path' => $filePath,
                        'hls_url' => $hlsUrl,
                        'thumbnail_url' => $thumbnailUrl,
                    ]);

                    return response()->json([
                        'result' => 'ok',
                        'message' => '재생 URL이 저장되었습니다.',
                    ], 200);
                } else {
                    Log::warning('VOD 콜백: HLS URL을 찾을 수 없음', [
                        'portfolio_id' => $portfolio->id,
                        'portfolio_video_id' => $portfolioVideo->id,
                        'job_id' => $jobId,
                        'file_path' => $filePath,
                        'outputs' => $outputs,
                    ]);

                    // HLS URL이 없어도 상태는 업데이트
                    $portfolioVideo->update([
                        'video_job_id' => $jobId,
                        'video_status' => 'complete',
                    ]);

                    return response()->json([
                        'result' => 'warning',
                        'message' => 'HLS URL을 찾을 수 없습니다.',
                    ], 200);
                }
            } elseif ($status === 'FAILED') {
                // 인코딩 실패 처리
                $portfolioVideo->update([
                    'video_job_id' => $jobId,
                    'video_status' => 'failed',
                ]);

                Log::error('VOD 인코딩 실패', [
                    'portfolio_id' => $portfolio->id,
                    'portfolio_video_id' => $portfolioVideo->id,
                    'job_id' => $jobId,
                    'file_path' => $filePath,
                ]);

                return response()->json([
                    'result' => 'ok',
                    'message' => '인코딩 실패 상태가 저장되었습니다.',
                ], 200);
            } elseif ($status === 'PROCESSING') {
                // 인코딩 진행 중 처리
                $portfolioVideo->update([
                    'video_job_id' => $jobId,
                    'video_status' => 'processing',
                ]);

                Log::info('VOD 인코딩 진행 중', [
                    'portfolio_id' => $portfolio->id,
                    'portfolio_video_id' => $portfolioVideo->id,
                    'job_id' => $jobId,
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
                    'jobId' => $request->input('jobId'),
                    'status' => $request->input('status'),
                    'categoryId' => $request->input('categoryId'),
                    'categoryName' => $request->input('categoryName'),
                    'input' => $request->input('input'),
                    'input.bucketName' => $request->input('input.bucketName'),
                    'input.filePath' => $request->input('input.filePath'),
                    'output' => $request->input('output'),
                ],
                'server_data' => $request->server->all(),
            ]);

            // 콘솔에도 출력 (개발 환경에서 확인용)
            if (config('app.debug')) {
                \Log::info('VOD 테스트 콜백 - 콘솔 출력', [
                    'jobId' => $request->input('jobId'),
                    'status' => $request->input('status'),
                    'filePath' => $request->input('input.filePath'),
                    'output_count' => count($request->input('output', [])),
                ]);
            }

            return response()->json([
                'result' => 'ok',
                'message' => '테스트 콜백이 로그에 저장되었습니다.',
                'logged_data' => [
                    'jobId' => $request->input('jobId'),
                    'status' => $request->input('status'),
                    'filePath' => $request->input('input.filePath'),
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

