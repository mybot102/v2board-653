# 流量更新优化 (Traffic Update Optimization)

## 问题 (Problem)

在用户数量很多的情况下，MySQL 数据库连接数会非常多，并且连接有时候不会正常关闭，导致空闲连接很多。

When there are many users, MySQL database connections become excessive, and connections sometimes don't close properly, leading to many idle connections.

## 解决方案 (Solution)

### 1. 批量处理流量更新 (Batch Traffic Updates)

新增了 `TrafficFetchBatchJob` 来批量处理用户流量更新，而不是为每个用户创建单独的任务。这样可以显著减少数据库连接数。

Added `TrafficFetchBatchJob` to process user traffic updates in batches instead of creating individual jobs per user. This significantly reduces database connections.

### 2. 数据库连接优化 (Database Connection Optimization)

在 `config/database.php` 中添加了以下配置：

Added the following configurations to `config/database.php`:

- `DB_PERSISTENT`: 持久连接选项 (Persistent connection option)
- `DB_TIMEOUT`: 连接超时时间 (Connection timeout)
- `DB_WAIT_TIMEOUT`: MySQL 空闲超时时间，默认 300 秒 (MySQL idle timeout, default 300 seconds)

### 3. 显式连接清理 (Explicit Connection Cleanup)

- 在 `TrafficFetchBatchJob` 中，每处理 100 个用户后显式关闭数据库连接
- 在原有的 `TrafficFetchJob` 中也添加了连接清理逻辑

After processing every 100 users in `TrafficFetchBatchJob`, explicitly close database connections. Also added connection cleanup logic to the original `TrafficFetchJob`.

## 配置 (Configuration)

### 环境变量 (Environment Variables)

在 `.env` 文件中可以配置以下参数：

You can configure the following parameters in the `.env` file:

```bash
# 是否使用持久连接（默认：false）
# Whether to use persistent connections (default: false)
DB_PERSISTENT=false

# 连接超时时间（秒，默认：3）
# Connection timeout (seconds, default: 3)
DB_TIMEOUT=3

# 数据库空闲超时时间（秒，默认：300）
# Database idle timeout (seconds, default: 300)
DB_WAIT_TIMEOUT=300
```

### 批量模式开关 (Batch Mode Toggle)

系统默认使用批量模式处理流量更新。如需使用旧的单独任务模式，可在 `config/v2board.php` 中设置：

The system uses batch mode by default for traffic updates. To use the old individual job mode, set in `config/v2board.php`:

```php
'traffic_fetch_batch_mode' => false,  // 设为 false 使用旧模式 (Set to false for legacy mode)
```

**注意**: 不建议关闭批量模式，除非遇到兼容性问题。

**Note**: It's not recommended to disable batch mode unless you encounter compatibility issues.

## 技术细节 (Technical Details)

### 批量更新流程 (Batch Update Process)

1. 服务器提交流量数据到控制器 (Server submits traffic data to controller)
2. `UserService::trafficFetch()` 将所有用户数据打包成一个批量任务 (Packs all user data into one batch job)
3. `TrafficFetchBatchJob` 将用户分成每 100 个一组进行处理 (Processes users in chunks of 100)
4. 每组处理完后显式断开数据库连接 (Explicitly disconnects database after each chunk)
5. 使用事务和重试机制处理死锁 (Uses transactions and retry mechanism for deadlocks)

### 性能优势 (Performance Benefits)

- **减少连接数**: 从"N个用户 = N个连接"降为"N个用户 / 100 = 连接数" (Reduces connections from "N users = N connections" to "N users / 100 = connections")
- **更快关闭**: 显式关闭连接，避免等待超时 (Explicitly closes connections instead of waiting for timeout)
- **批量操作**: 使用 SQL UPDATE 而不是加载模型，更高效 (Uses SQL UPDATE instead of loading models, more efficient)

## 升级说明 (Upgrade Notes)

这些更改向后兼容，无需修改现有配置即可使用。系统会自动使用批量模式处理流量更新。

These changes are backward compatible and work without modifying existing configurations. The system will automatically use batch mode for traffic updates.

如果遇到问题，可以临时关闭批量模式，然后联系技术支持。

If you encounter issues, you can temporarily disable batch mode and contact technical support.
