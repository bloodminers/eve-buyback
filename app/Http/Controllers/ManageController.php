<?php

namespace App\Http\Controllers;

use App\EveOnline\Helper;
use App\EveOnline\Parser;
use App\EveOnline\Refinery;
use App\Http\Requests;
use App\Http\Controllers\Controller;
use App\Models\API\Contract;
use App\Models\API\Outpost;
use App\Models\SDE\StaStation;
use App\Models\SDE\InvCategory;
use App\Models\SDE\InvGroup;
use App\Models\SDE\InvType;
use App\Models\Item;
use App\Models\Setting;
use Carbon\Carbon;
use DB;
use Illuminate\Cache\Repository as Cache;
use Illuminate\Http\Request;

class ManageController extends Controller
{
	/**
	* @var \Illuminate\Cache\Repository
	*/
	private $cache;

	/**
	* @var \Carbon\Carbon
	*/
	private $carbon;

	/**
	* @var \App\Models\API\Contract
	*/
	private $contract;

	/**
	* @var \App\Models\API\Outpost
	*/
	private $outpost;

	/**
	* @var \App\Models\SDE\StaStation
	*/
	private $station;

	/**
	* @var \App\Models\InvCategory
	*/
	private $group;

	/**
	* @var \App\Models\InvGroup
	*/
	private $category;

	/**
	* @var \App\Models\InvType
	*/
	private $type;

	/**
	* @var \App\Models\Item
	*/
	private $item;

	/**
	* @var \App\EveOnline\Helper
	*/
	private $helper;

	/**
	* @var \App\EveOnline\Parser
	*/
	private $parser;

	/**
	* @var \App\EveOnline\Refinery
	*/
	private $refinery;

	/**
	* @var \Illuminate\Http\Request
	*/
	private $request;

	/**
	* @var \App\Models\Setting
	*/
	private $setting;

	/**
	* Constructs the class.
	* @param  \Illuminate\Cache\Repository $cache
	* @param  \Carbon\Carbon               $carbon
	* @param  \App\Models\API\Contract     $contract
	* @param  \App\Models\API\Outpost      $outpost
	* @param  \App\Models\SDE\StaStation   $station
	* @param  \App\Models\SDE\InvType      $type
	* @param  \App\Models\Item             $item
	* @param  \App\EveOnline\Parser        $parser
	* @param  \App\EveOnline\Refinery      $refinery
	* @param  \Illuminate\Http\Request     $request
	* @param  \App\Models\Setting          $setting
	*/
	public function __construct(
		Cache       $cache,
		Carbon      $carbon,
		Contract    $contract,
		Outpost     $outpost,
		StaStation  $station,
		InvCategory $category,
		InvGroup    $group,
		InvType     $type,
		Item        $item,
		Helper      $helper,
		Parser      $parser,
		Refinery    $refinery,
		Request     $request,
		Setting     $setting
	) {
		$this->cache    = $cache;
		$this->carbon   = $carbon;
		$this->contract = $contract;
		$this->outpost  = $outpost;
		$this->station  = $station;
		$this->category = $category;
		$this->group    = $group;
		$this->type     = $type;
		$this->item     = $item;
		$this->helper   = $helper;
		$this->parser   = $parser;
		$this->refinery = $refinery;
		$this->request  = $request;
		$this->setting  = $setting;
	}

	/**
	 * Handles displaying contracts that are assigned to the character or corporation.
	 * @return \Illuminate\Http\Response
	 */
	public function contract()
	{
		$buyback_items = $this->item->with('type')->get();
		$contracts     = $this->contract
			->with('items')
			->with('items.type')
			->with('items.type.group')
			->with('items.type.group.category')
			->with('items.type.materials')
			->where('status', 'Outstanding')
			->orderBy('contractID', 'DESC')
			->get();

		$buying = $selling = [];

		foreach ($contracts as $contract) {
			$items = $this->parser->convertContractToItems($contract);

			if ($contract->price > 0) {
				$buying[] = $buyback = $this->refinery->calculateBuyback($items, $buyback_items);

				// Insert the profit margin.
				$buyback->totalMargin = 0;

				if ($contract->price > 0 && $buyback->totalValue > 0) {
					$buyback->totalMargin = 100 - ($contract->price / $buyback->totalValue * 100);
				}

				// Insert convenience items into the buyback object.
				$buyback->contract        = $contract;
				$buyback->contractPrice   = $contract->price;
				$buyback->contractStation = $this->helper->convertStationIdToModel ($contract->startStationID);
				$buyback->contractIssuer  = $this->helper->convertCharacterIdToName($contract->issuerID      );

			} else if ($contract->reward > 0) {
			}
		}

		return view('manage.contract')
			->withBuying ($buying )
			->withSelling($selling);
	}

	/**
	 * Handles displaying the configuration page.
	 * @return \Illuminate\Http\Response
	 */
	public function config()
	{
		$motd  = $this->setting->where('key', 'motd')->first();
		$motd  = $motd ? $motd->value : '';

		$items = $this->item
			->with('type')
			->with('type.group')
			->with('type.group.category')
			->get();

		return view('manage.config')
			->withMotd ($motd )
			->withItems($items);
	}

	/**
	 * Handles updating the motd.
	 * @return \Illuminate\Http\JsonResponse
	 */
	public function motd()
	{
		if (!$this->request->ajax()) {
			return response()->json(['result' => false]);
		}

		$text = strip_tags($this->request->input('text')) ?: '';

		if (strlen($text) == 0) {
			$this->setting->where('key', 'motd')->delete();

			return response()->json([
				'result'  => true,
				'message' => trans('buyback.config.motd.removed'),
			]);
		}

		if (strlen($text) > 5000) {
			return response()->json([
				'result'  => false,
				'message' => trans('validation.max.string',
					['attribute' => 'text', 'max' => 5000]),
			]);
		}

		$this->setting->updateOrCreate(
			['key'   => 'motd'],
			['value' => $text ]
		);

		return response()->json([
			'result'  => true,
			'message' => trans('buyback.config.motd.updated'),
		]);
	}

	/**
	 * Gets a list of buyback items.
	 * @return \Illuminate\Http\JsonResponse
	 */
	public function getItems()
	{
		return $this->item
			->with('type')
			->with('type.group')
			->with('type.group.category')
			->get()
			->toJson();
	}

	/**
	 * Gets a paginated list of inventory types.
	 * @return \Illuminate\Http\JsonResponse
	 */
	public function getTypes()
	{
		$query = htmlspecialchars(strip_tags($this->request->input('query')));

		return $this->type
			->where('published', true)
			->where('typeName', 'LIKE', "%{$query}%")
			->paginate(20);
	}

	/**
	 * Gets a paginated list of inventory groups.
	 * @return \Illuminate\Http\JsonResponse
	 */
	public function getGroups()
	{
		$query = htmlspecialchars(strip_tags($this->request->input('query')));

		return $this->group
			->where('published', true)
			->where('groupName', 'LIKE', "%{$query}%")
			->paginate(20);
	}

	/**
	 * Gets a paginated list of inventory categories.
	 * @return \Illuminate\Http\JsonResponse
	 */
	public function getCategories()
	{
		$query = htmlspecialchars(strip_tags($this->request->input('query')));

		return $this->category
			->where('published', true)
			->where('categoryName', 'LIKE', "%{$query}%")
			->paginate(20);
	}

	/**
	 * Handles adding new buyback items.
	 * @return \Illuminate\Http\JsonResponse
	 */
	public function addItems()
	{
		if (!$this->request->ajax()) {
			return response()->json(['result' => false]);
		}

		// Get the input that can be applied to all items.
		$values = [
			'buyRaw'       => $this->request->input('buyRaw'      ) ? true : false,
			'buyRecycled'  => $this->request->input('buyRecycled' ) ? true : false,
			'buyRefined'   => $this->request->input('buyRefined'  ) ? true : false,
			'buyModifier'  => $this->request->input('buyModifier' ) ?      :  0.00,
			'sell'         => $this->request->input('sell'        ) ? true : false,
			'sellModifier' => $this->request->input('sellModifier') ?      :  0.00,
			'lockPrices'   => $this->request->input('lockPrices'  ) ? true : false,
			'buyPrice'     => 0.00,
			'sellPrice'    => 0.00,
		];

		$values['buyModifier'] = is_numeric($values['buyModifier'])
			? (double)$values['buyModifier' ] : 0.00;

		$values['sellModifier'] = is_numeric($values['sellModifier'])
			? (double)$values['sellModifier'] : 0.00;

		// Get the types being added.
		$ids   = $this->request->input('types') ?: [];
		$types = $this->type->whereIn('typeID', $ids)->get();

		// Get the types being added from a group.
		$ids    = $this->request->input('groups') ?: [];
		$groups = $this->group->with('types')->whereIn('groupID', $ids)->get();
		$groups->each(function ($group) use (&$types) {
			$group->types->each(function ($type) use (&$types) {
				$types->push($type);
			});
		});

		// Get the types being added from a category.
		$ids        = $this->request->input('categories') ?: [];
		$categories = $this->category->with('types')->whereIn('categoryID', $ids)->get();
		$categories->each(function ($category) use (&$types) {
			$category->types->each(function ($type) use (&$types) {
				$types->push($type);
			});
		});

		// Remove duplicate types.
		$types = $types->unique('typeID');

		// Add the types to the database as items.
		DB::transaction(function () use ($types, $values) {
			$types->each(function ($type) use($values) {
				try {
					$item = $this->item->find($type->typeID);
					if ($item) { continue; };

					$this->item->create([
						'typeID'       => $type->typeID,
						'typeName'     => $type->typeName,
						'buyRaw'       => $values['buyRaw'      ],
						'buyRecycled'  => $values['buyRecycled' ],
						'buyRefined'   => $values['buyRefined'  ],
						'buyModifier'  => $values['buyModifier' ],
						'buyPrice'     => $values['buyPrice'    ],
						'sell'         => $values['sell'        ],
						'sellModifier' => $values['sellModifier'],
						'lockPrices'   => $values['lockPrices'  ],
						'sellPrice'    => $values['sellPrice'   ],
					]);

				} catch (\Exception $e) {
					if (!$this->request->ajax()) {
						return response()->json([
							'result'  => false,
							'message' => trans('buyback.config.items.add_failed'),
						]);
					}
				}
			});
		});

		return response()->json([
			'result'  => true,
			'message' => trans('buyback.config.items.added'),
		]);
	}

	/**
	 * Handles updating the buyback items.
	 * @return \Illuminate\Http\JsonResponse
	 */
	public function updateItems()
	{
		if (!$this->request->ajax()) {
			return response()->json(['result' => false]);
		}

		// Get the input that can be applied to all items.
		$multiple = [
			'buyRaw'       => $this->request->input('buyRaw'      ) ? true : false,
			'buyRecycled'  => $this->request->input('buyRecycled' ) ? true : false,
			'buyRefined'   => $this->request->input('buyRefined'  ) ? true : false,
			'buyModifier'  => $this->request->input('buyModifier' ) ?      :  0.00,
			'sell'         => $this->request->input('sell'        ) ? true : false,
			'sellModifier' => $this->request->input('sellModifier') ?      :  0.00,
			'lockPrices'   => $this->request->input('lockPrices'  ) ? true : false,
		];

		$multiple['buyModifier'] = is_numeric($multiple['buyModifier'])
			? (double)$multiple['buyModifier' ] : 0.00;

		$multiple['sellModifier'] = is_numeric($multiple['sellModifier'])
			? (double)$multiple['sellModifier'] : 0.00;

		// Get the input that can only be applied to a single item.
		$single = [
			'buyPrice'  => $this->request->input('buyPrice' ) ?: 0.00,
			'sellPrice' => $this->request->input('sellPrice') ?: 0.00,
		];

		$single['buyPrice'] = is_numeric($single['buyPrice'])
			? (double)$single['buyPrice' ] : 0.00;

		$single['sellPrice'] = is_numeric($single['sellPrice'])
			? (double)$single['sellPrice'] : 0.00;

		// Get the items being updated.
		$ids   = explode(',', $this->request->input('items'));
		$ids   = count($ids) && $ids[0] != '' ? $ids : [];
		$items = $this->item->whereIn('typeID', $ids)->get();

		// Update a single item.
		if ($items->count() == 1) {
			$items[0]->update(array_merge($multiple, $single));

		// Update multiple items.
		} else if ($items->count() > 1) {
			$items->each(function ($item) use ($multiple) {
				$item->update($multiple);
			});

		} else {
			return response()->json([
				'result'  => false,
				'message' => trans('buyback.config.items.update_failed'),
			]);
		}

		return response()->json([
			'result'  => true,
			'message' => trans('buyback.config.items.updated'),
		]);
	}
}
