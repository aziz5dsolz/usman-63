<?php


namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use App\Models\BacklogProject;
use App\Models\Backlogs;
use App\Models\Vote;
use App\Models\CoinDistribution;
use App\Models\PaymentSetting;
use Illuminate\Support\Facades\Log;
use Yajra\DataTables\Facades\DataTables;

class CoinDistributionController extends Controller
{
    public function index()
    {
        $paymentSetting  = PaymentSetting::find(1);
        $total_year = $paymentSetting->total_yearly_tokens;
        $dev_per = $paymentSetting->dev_account_percentage;
        $total_available =  ($total_year * $dev_per) / 100;

        $total_distributed = CoinDistribution::where('status', 'completed')
            ->sum(DB::raw('CAST(amount AS DECIMAL(18,8))'));

        $total_developer = CoinDistribution::where('status', 'completed')
            ->where('reference_type', 'App\Models\BacklogProject')
            ->sum(DB::raw('CAST(amount AS DECIMAL(18,8))'));

        $total_voter = CoinDistribution::where('status', 'completed')
            ->where('reference_type', 'App\Models\Vote')
            ->sum(DB::raw('CAST(amount AS DECIMAL(18,8))'));

        $data['total_distributed'] = $total_distributed;
        $data['total_developer'] = $total_developer;
        $data['total_voter'] = $total_voter;
        $data['total_available'] = $total_available;

        return view('admin.coin_distribution', compact('data'));
    }

    public function getCoinDistribution(Request $request)
    {
        $totalUsers = DB::table('users')->where('role', '2')->count();

        $records = Backlogs::with(['BacklogCategory', 'BacklogStatus', 'User'])
            ->whereNotNULL('paid_at')
            ->withCount(['Projects as submission'])
            ->withCount([
                'Projects as voting_turn_out' => function ($query) {
                    $query->join('votes', 'backlog_projects.id', '=', 'votes.project_id')
                        ->select(DB::raw('COUNT(DISTINCT votes.user_id)'));
                }
            ]);

        $filters = json_decode($request->filters, true) ?? [];
        $records = $this->recordFilter($records, $filters);


        return DataTables::of($records)
            ->addColumn('voting_percentage', function ($backlog) use ($totalUsers) {
                return $totalUsers > 0 ? ($backlog->voting_turn_out / $totalUsers) * 100 : 0;
            })
            ->addColumn('total_users', function ($backlog) use ($totalUsers) {
                return $totalUsers;
            })
            ->addColumn('formatted_created_at', function ($backlog) {
                return $backlog->created_at ? $backlog->created_at->format('F d, Y') : 'N/A';
            })
            ->addColumn('formatted_deadline', function ($backlog) {
                return $backlog->deadline ? \Carbon\Carbon::parse($backlog->deadline)->format('d M Y') : 'N/A';
            })
            ->addColumn('formatted_paid_at', function ($backlog) {
                return $backlog->paid_at ? \Carbon\Carbon::parse($backlog->paid_at)->format('F d, Y') : 'N/A';
            })
            ->addColumn('category_name', function ($backlog) {
                return $backlog->backlog_category ? $backlog->backlog_category->name : 'N/A';
            })
            // ->addColumn('status_name', function ($backlog) {

            //     return $backlog->backlog_status ? $backlog->backlog_status->name : 'N/A';

            // })
            ->addColumn('status_name', function ($backlog) {
                return ucfirst($backlog->status) ?? 'N/A';
            })

            ->addColumn('user_name', function ($backlog) {
                return $backlog->user ? ($backlog->user->first_name . ' ' . $backlog->user->last_name) : 'N/A';
            })
            // ->addColumn('status_badge', function ($backlog) {
            //     $spanBg = "";
            //     if ($backlog->status_id == 1) {
            //         $spanBg = "bg-secondary";
            //     } else if ($backlog->status_id == 2) {
            //         $spanBg = "bg-primary";
            //     } else if ($backlog->status_id == 3) {
            //         $spanBg = "bg-danger";
            //     } else if ($backlog->status_id == 4) {
            //         $spanBg = "bg-light";
            //     } else if ($backlog->status_id == 5) {
            //         $spanBg = "bg-success";
            //     }
            //     $statusName = $backlog->backlog_status ? $backlog->backlog_status->name : 'N/A';
            //     return '<span class="badge ' . $spanBg . '">' . $statusName . '</span>';
            // })

            // ->addColumn('status_badge', function ($backlog) {
            //     $spanBg = "";
            //     // Use 'status' instead of 'status_id'
            //     if ($backlog->status == 'pending') {
            //         $spanBg = "bg-secondary";
            //     } else if ($backlog->status == 'in_progress') {
            //         $spanBg = "bg-primary";
            //     } else if ($backlog->status == 'failed') {
            //         $spanBg = "bg-danger";
            //     } else if ($backlog->status == 'cancelled') {
            //         $spanBg = "bg-light";
            //     } else if ($backlog->status == 'completed') {
            //         $spanBg = "bg-success";
            //     }

            //     // Use the actual status value instead of trying to access backlog_status relationship
            //     $statusName = ucfirst($backlog->status) ?? 'N/A';
            //     return '<span class="badge ' . $spanBg . '">' . $statusName . '</span>';
            // })

            ->addColumn('status_badge', function ($backlog) {
                $spanBg = "";
                $statusName = 'N/A';

                // Check if we have a valid status relationship
                if ($backlog->BacklogStatus) {
                    $statusName = $backlog->BacklogStatus->name;
                    $statusId = $backlog->status_id;
                } else {
                    // Fallback based on known status IDs
                    $statusId = $backlog->status_id;
                    switch ($statusId) {
                        case 1:
                            $statusName = 'Pending';
                            break;
                        case 2:
                            $statusName = 'Approved';
                            break;
                        case 3:
                            $statusName = 'Rejected';
                            break;
                        case 4:
                            $statusName = 'Doing';
                            break;
                        case 5:
                            $statusName = 'Done';
                            break;
                        case 6:
                            $statusName = 'Paid';
                            break;
                        default:
                            $statusName = 'Unknown';
                    }
                }

                // Set badge color based on status_id
                switch ($statusId) {
                    case 1: // Pending
                        $spanBg = "bg-secondary text-white";
                        break;
                    case 2: // Approved
                        $spanBg = "bg-primary text-white";
                        break;
                    case 3: // Rejected
                        $spanBg = "bg-danger text-white";
                        break;
                    case 4: // Doing
                        $spanBg = "bg-warning text-dark";
                        break;
                    case 5: // Done
                        $spanBg = "bg-info text-white";
                        break;
                    case 6: // Paid
                        $spanBg = "bg-success text-white";
                        break;
                    default:
                        $spanBg = "bg-dark text-white";
                }

                return '<span class="badge ' . $spanBg . '">' . $statusName . '</span>';
            })



            ->addColumn('submission_badge', function ($backlog) {
                $submissionColor = "";
                if ($backlog->submission == "0") {
                    $submissionColor = "black";
                } else if ($backlog->submission == "1") {
                    $submissionColor = "orange";
                } else if ($backlog->submission == "2") {
                    $submissionColor = "yellow";
                } else {
                    $submissionColor = "green";
                }
                return '<a href="/admin/projects/' . $backlog->id . '" style="text-decoration: none;">
                     <span class="badge" style="color:' . $submissionColor . '">' . $backlog->submission . '</span>
                    </a>';
            })
            ->addColumn('actions', function ($backlog) {
                return '<div class="dropdown">
                        <button class="btn btn-secondary btn-sm dropdown-toggle"
                            type="button" data-bs-toggle="dropdown"
                            aria-expanded="false">
                            Actions
                        </button>
                            <ul class="dropdown-menu">
                                <li>
                                    <a class="dropdown-item view-payment-detail" id="' . $backlog->id . '" >View</a>
                                </li>                      
                            </ul>
                        </div>';
            })
            ->rawColumns(['status_badge', 'submission_badge', 'actions'])
            ->make(true);
    }
    public function recordFilter($records, $filters)
    {
        $hasFilters = false;

        // Apply sorting filters
        if (!empty($filters['sort']['column']) && !empty($filters['sort']['order'])) {
            $records->orderBy($filters['sort']['column'], $filters['sort']['order']);
            $hasFilters = true;
        }

        // Apply status filters
        if (!empty($filters['advanceStatusFilter'])) {
            $records->where('status', $filters['advanceStatusFilter']);
            $hasFilters = true;
        }

        // Apply category filters
        if (!empty($filters['advanceTypeFilter'])) {
            $records->where('reference_type', $filters['advanceTypeFilter']);
            $hasFilters = true;
        }

        // Apply date range filters
        if (!empty($filters['date_range']['start']) && !empty($filters['date_range']['end'])) {
            $startDate = $filters['date_range']['start'] . ' 00:00:00';
            $endDate = $filters['date_range']['end'] . ' 23:59:59';
            $records->whereBetween('created_at', [$startDate, $endDate]);
            $hasFilters = true;
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $records->where(function ($query) use ($search) {
                $query->where('backlogs.title', 'LIKE', "%$search%")
                    ->orWhere('backlogs.id', 'LIKE', "%$search%")
                    ->orWhereHas('BacklogCategory', function ($q) use ($search) {
                        $q->where('name', 'LIKE', "%$search%");
                    })
                    ->orWhereHas('BacklogStatus', function ($q) use ($search) {
                        $q->where('name', 'LIKE', "%$search%");
                    })
                    ->orWhereHas('User', function ($q) use ($search) {
                        $q->where('first_name', 'LIKE', "%$search%")
                            ->orWhere('last_name', 'LIKE', "%$search%");
                    });
            });
            $hasFilters = true;
        }

        // Apply default ordering if no filters were applied
        if (!$hasFilters) {
            $records->orderBy('created_at', 'DESC');
        }

        return $records;
    }

    public function getPendingAmountBacklogList()
    {
        $oldWeek  = [];
        $lastWeek  = [];
        $data = [];
        $paymentSetting = PaymentSetting::find(1);
        $reward_submission_percentage = $paymentSetting->reward_submission_percentage;
        $reward_code_review_percentage = $paymentSetting->reward_code_review_percentage;

        //====================old Week=====================================//
        $backlogs =  Backlogs::with(['BacklogCategory', 'BacklogStatus', 'User'])
            ->where('status_id', 5)
            ->whereNull('paid_at')
            // ->whereRaw('YEARWEEK(`done_at`, 1) = YEARWEEK(NOW() - INTERVAL 1 WEEK, 1)')
            ->get();


        foreach ($backlogs as $index => $backlog) {
            $backlog_id   = $backlog->id;
            $coin = $backlog->coin;
            $developer =  ($coin * ($reward_submission_percentage / 100));
            $voter = ($coin * ($reward_code_review_percentage / 100));

            $backlog->developer = (float) $developer;
            $backlog->voter = (float) $voter;

            $backlog['projects'] = $this->getProjects($backlog_id);
            $oldWeek[] =    $backlog;
        }

        //==================== last Week=====================================//
        $backlogs2 =  Backlogs::with(['BacklogCategory', 'BacklogStatus', 'User'])
            ->where('status_id', 5)
            ->whereNull('paid_at')
            // ->whereRaw('YEARWEEK(`done_at`, 1) < YEARWEEK(NOW() - INTERVAL 1 WEEK, 1)')
            ->get();

        foreach ($backlogs2 as $index => $backlog) {
            $backlog_id   = $backlog->id;
            $coin = $backlog->coin;
            $developer =  ($coin * ($reward_submission_percentage / 100));
            $voter = ($coin * ($reward_code_review_percentage / 100));

            $backlog->developer = (float) $developer;
            $backlog->voter = (float) $voter;

            $backlog['projects'] = $this->getProjects($backlog_id);
            $lastWeek[] =    $backlog;
        }

        $data['oldWeek'] = $oldWeek;
        $data['lastWeek'] = $lastWeek;

        return response()->json(['status' => 200, 'data' => $data]);
    }

    private  function getProjects($backlog_id)
    {
        $projects = BacklogProject::with('User')->withCount(['votes as upvotes_count' => function ($query) {
            $query->where('vote_type', 'up');
        }])
            ->withCount(['votes as downvotes_count' => function ($query) {
                $query->where('vote_type', 'down');
            }])
            ->where('status', '3')
            ->where('backlog_projects.backlog_id', $backlog_id)
            ->having('upvotes_count', '>=', 1)
            ->orderBy('upvotes_count', 'desc')
            ->orderBy('created_at', 'asc')
            ->limit(3)
            ->get();

        return $projects;
    }

    public function distributionStatus(Request $request)
    {
        $id = $request->id;
        $status = $request->status;

        $record = CoinDistribution::find($id);
        $record->status = $status;
        $record->save();

        return response()->json(['status' => 200, 'message' => 'status change successfully']);
    }

    public function addPayment(Request $request)
    {
        $backlog_id = $request->backlog_id;

        try {
            DB::beginTransaction();

            $backlogs =  Backlogs::where('status_id', 5)
                ->where('id', $backlog_id)
                ->whereNull('paid_at')
                ->first();

            $backlog_id   = $backlogs->id;
            $totalRewardPool = $backlogs->coin;

            $paymentSetting = PaymentSetting::find(1);
            $reward_submission_percentage = $paymentSetting->reward_submission_percentage;
            $reward_code_review_percentage = $paymentSetting->reward_code_review_percentage;

            $projectRewardPool = $totalRewardPool * ($reward_submission_percentage / 100);
            $voterRewardPool = $totalRewardPool * ($reward_code_review_percentage / 100);

            $first_place_percentage = $paymentSetting->first_place_percentage;
            $second_place_percentage = $paymentSetting->second_place_percentage;
            $third_place_percentage = $paymentSetting->third_place_percentage;

            $rewardDistribution = [
                $first_place_percentage / 100,
                $second_place_percentage / 100,
                $third_place_percentage / 100
            ];

            $projects = BacklogProject::withCount(['votes as upvotes_count' => function ($query) {
                $query->where('vote_type', 'up');
            }])
                ->where('status', '3')
                ->where('backlog_projects.backlog_id', $backlog_id)
                ->having('upvotes_count', '>=', 1)
                ->orderBy('upvotes_count', 'desc')
                ->orderBy('created_at', 'asc')
                ->limit(3)
                ->get();

            if ($projects->count() < 3) {
                return response()->json(['status' => 400, 'message' => 'Not enough completed projects to process payments']);
            }

            foreach ($projects as $index => $project) {
                $rewardAmount = $projectRewardPool * $rewardDistribution[$index];

                CoinDistribution::create([
                    'user_id'       => $project->uploaded_by,
                    'amount'        => $rewardAmount,
                    'reference_id'  => $project->id,
                    'reference_type' => BacklogProject::class,
                    'status' => 'completed',
                ]);

                $project_id = $project->id;
                BacklogProject::where('id', $project_id)->update(['paid_at' => now()]);
            }

            $voterVotes = Vote::whereIn('project_id', $projects->pluck('id'))
                ->get(['id', 'user_id']);

            $totalVoters = $voterVotes->count();

            if ($totalVoters > 0) {
                $rewardPerVoter = $voterRewardPool / $totalVoters;

                $coinDistributions = $voterVotes->map(function ($vote) use ($rewardPerVoter) {
                    return [
                        'user_id'       => $vote->user_id,
                        'amount'        => $rewardPerVoter,
                        'reference_id'  => $vote->id,
                        'reference_type' => Vote::class,
                        'status' => 'completed',
                        'created_at'    => now(),
                        'updated_at'    => now(),
                    ];
                });

                CoinDistribution::insert($coinDistributions->toArray());

                Vote::whereIn('id', $voterVotes->pluck('id'))
                    ->update(['paid_at' => now()]);
            } else {
                return response()->json(['status' => 400, 'message' => 'Not enough completed votes to process payments']);
            }

            Backlogs::where('id', $backlog_id)->update(['paid_at' => now()]);

            DB::commit();
            return response()->json(['status' => 200, 'message' => 'Payments processed successfully']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Payment processing failed: ' . $e->getMessage());
            return response()->json(['status' => 500, 'message' => 'Payment failed: ' . $e->getMessage()]);
        }
    }

    public function paymentDetails(Request $request)
    {
        $backlog = Backlogs::findOrFail($request->backlog_id);

        $projectIds = $backlog->projects()->pluck('id');
        $voteIds = Vote::whereIn('project_id', $projectIds)->pluck('id');

        $data = CoinDistribution::with('user')->where(function ($query) use ($projectIds, $voteIds) {
            $query->where(function ($q) use ($projectIds) {
                $q->where('reference_type', 'App\Models\BacklogProject')
                    ->whereIn('reference_id', $projectIds);
            })->orWhere(function ($q) use ($voteIds) {
                $q->where('reference_type', 'App\Models\Vote')
                    ->whereIn('reference_id', $voteIds);
            });
        })->get();

        return response()->json(['status' => 200, 'data' => $data]);
    }
}
