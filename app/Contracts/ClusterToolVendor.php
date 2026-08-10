<?php

namespace App\Contracts;

/**
 * Marker + minimum contract every category's vendor (DataTool, FlowTool,
 * GitForgeTool, ChatTool, DesignTool, TasksTool, DeskTool, and the 22
 * single-vendor classes under app/Vendors/) implements, so the category
 * enum's `vendor()` dispatch method has a return type to hang `instanceof`
 * capability checks off of.
 *
 * A product name is the one fact every vendor can always answer — see
 * HasLabel — so it's the only MANDATORY method. Every other capability
 * (SMTP/OIDC wiring, Commons tenants/buckets, workload components, ...) is a
 * separate OPTIONAL contract a vendor implements only when it actually
 * supports it; ClusterTool checks each via `instanceof`, exactly like
 * HasHiddenComponents already works for ProvidesSelectOptions/
 * ProvidesCommandOptions.
 */
interface ClusterToolVendor extends HasLabel {}
