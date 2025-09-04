<?php

namespace Sway\Services;

use Exception;
use Carbon\Carbon;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Sway\Types\Payload;
use Jenssegers\Agent\Agent;
use Sway\Utils\StringUtils;
use Sway\Models\RefreshToken;
use Sway\Services\RedisService;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\Auth\Authenticatable;

class JWTTokenService
{
    // The secret key for signing the JWT
    private static $secretKey;
    private static $accessTokenExpiration;
    private static $refreshTokenExpiration;
    protected $redisService;

    public function __construct(RedisService $redisService)
    {
        $this->redisService = $redisService;
        self::$secretKey = config('sway.token.secret_key', "GGPoDl2y3ayUszNnw/wQQ8++RR5r89poozLQOc8t4OM="); // Default to 60 minutes if not set
        self::$accessTokenExpiration = config('sway.token.access_token_expiration', 60); // Default to 60 minutes if not set
        self::$refreshTokenExpiration = config('sway.token.refresh_token_expiration', 14); // Default to (14) if not set
    }

    /**
     * Generate JWT token
     * 
     * @param Authenticatable $user
     * @param string $model
     * @return string
     */
    public function generateToken(Authenticatable $user, $model)
    {
        $ipAddress = request()->ip();
        $agent = new Agent();
        $platform = $agent->platform();
        $browser = $agent->browser();
        $modelName = StringUtils::getModelName($model);

        // Set token expiration times
        $accessTokenExpiresAt = now()->addMinutes(self::$accessTokenExpiration); // Access token expires in 1 hour
        $refreshTokenExpiresAt = now()->addDays(self::$refreshTokenExpiration); // Refresh token expires in 2 weeks
        // $refreshTokenExpiresAt = now()->addDays(self::$refreshTokenExpiration); // Refresh token expires in 2 weeks

        $accessPayload = [
            'tokenable_id' =>  $user->id,
            'type' =>  $modelName,
            'expires_at' => $accessTokenExpiresAt,
            'role_id' => $user->role_id
        ];
        $refreshPayload = [
            'tokenable_id' =>  $user->id,
            'type' =>  $modelName,
            'expires_at' => $refreshTokenExpiresAt,
            'role_id' => $user->role_id
        ];
        $accessToken = JWT::encode($accessPayload, self::$secretKey, "HS256");
        $refreshToken = JWT::encode($refreshPayload, self::$secretKey, "HS256");


        // Store the tokens in the RefreshToken model
        $token = RefreshToken::create([
            'tokenable_id' => $user->id,  // Ensure you provide the tokenable_id
            'tokenable_type' => $modelName,
            'platform' => $platform,
            'browser' => $browser,
            'ip_address' => $ipAddress,
            'access_token' => $accessToken, // Save hashed access token for security
            'refresh_token' => $refreshToken, // Save hashed refresh token for security
            'access_token_expires_at' => $accessTokenExpiresAt,
            'refresh_token_expires_at' => $refreshTokenExpiresAt,
        ]);

        // Generate key
        $key = StringUtils::getRedisKey($modelName, $user->id);

        $this->redisService->storeTokenWithExpiry($key, $accessToken);
        return [
            "access_token" => $token->access_token,
            "refresh_token" => $token->refresh_token,
            "logged_in_device" => [
                'ip_address' => $ipAddress,
                'platform' => $platform,
                'browser' => $browser,
            ],
        ];
    }

    /**
     * Destroy JWT token
     * 
     * @param Authenticatable $user
     * @return bool
     */
    public function invalidateToken(Authenticatable $user)
    {
        $token = request()->cookie('access_token',  null);
        if (empty($token))
            return null;
        // 1. Remove From Redis
        $payload = $this->decodeToken($token);
        // 2. Check token in Redis
        $key = StringUtils::getRedisKey($payload->getType(), $payload->getTokenableId());
        $this->redisService->deleteToken($key);
        // 2. Remove from database
        $deletedCount = DB::table('refresh_tokens')
            ->where('access_token', $token)
            ->delete();

        if ($deletedCount > 0) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Generate JWT Refresh token
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function refreshToken()
    {
        $accessToken = request()->cookie('access_token',  null);
        if (empty($accessToken)) {
            return response()->json(
                [
                    'message' => 'Invalid Token.',
                    "access_token" => null
                ],
                401
            );
        }

        // 1. Get payload
        $payload = $this->decodeToken($accessToken);
        // 2. Search database
        $tokenRecord = RefreshToken::where('access_token', $accessToken)
            ->first();

        // Token found
        if ($tokenRecord) {
            $tokenableId = $payload->getTokenableId();
            $type = $payload->getType();
            $role_id = $payload->getRoleId();
            $key = StringUtils::getRedisKey($type, $tokenableId);

            // 3. Check If refresh token is expired
            if ($this->isTokenExpired($tokenRecord->refresh_token_expires_at)) {
                $tokenRecord->delete();
                $this->redisService->deleteToken($key);
                return response()->json(
                    [
                        'message' => 'Refresh Token expired.',
                        'type' => null,
                        "access_token" => null
                    ],
                    401
                );
            }

            // 4.
            $accessTokenExpiresAt = now()->addMinutes(self::$accessTokenExpiration); // Access token expires in 1 hour
            $accessPayload = [
                'tokenable_id' =>  $tokenableId,
                'type' =>  $type,
                'expires_at' => $accessTokenExpiresAt,
                'role_id' => $role_id
            ];
            $newAccessToken = JWT::encode($accessPayload, self::$secretKey, "HS256");
            $tokenRecord->access_token_expires_at = $accessTokenExpiresAt;
            $tokenRecord->access_token = $newAccessToken;
            $tokenRecord->save();

            // 2. Store in redis
            $this->redisService->storeTokenWithExpiry($key, $newAccessToken);
            return response()->json(
                [
                    'message' => 'Success.',
                    'type' => $type,
                    "access_token" =>  $newAccessToken
                ],
                200
            );
        } else {
            return response()->json(
                [
                    'message' => 'Unauthorized, Token is expired long time ago.',
                    'type' => null,
                    "access_token" =>  null
                ],
                401
            );
        }
    }

    /**
     * Decode the JWT token
     * 
     * @param string $token
     * @return Payload
     */
    public function decodeToken($token)
    {
        $decodedPayload = JWT::decode($token, new Key(self::$secretKey, 'HS256'));
        $payload = new Payload(
            $decodedPayload->tokenable_id,
            $decodedPayload->type,
            $decodedPayload->expires_at,
            $decodedPayload->role_id,
        );

        return $payload;
    }
    /**
     * Validate the JWT token
     * 
     * @param string $token
     * @return boolean
     */
    public function isTokenExpired($expires_at)
    {
        // Check if the token has expired
        $expiresAtTimestamp = Carbon::parse($expires_at)->timestamp;
        if (Carbon::now()->timestamp > $expiresAtTimestamp) {
            return true;
        }
        return false;
    }
}
